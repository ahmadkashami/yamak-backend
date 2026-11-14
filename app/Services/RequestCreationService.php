<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Models\AppUser;
use App\Models\Driver;
use App\Models\RequestIdempotencyKey;
use App\Models\ServiceRequest;
use App\Models\Vehicle;
use App\Support\Idempotency\RequestFingerprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Atomic, idempotent ServiceRequest creation.
 *
 * Two explicit entry points coexist during B2.5C:
 *
 *   - create(...)        legacy v1 path, still wired to the live Mobile v1
 *                        controller. Its signature, fingerprint, status
 *                        (`active`), replay behaviour and HTTP-visible behaviour
 *                        are preserved unchanged until C3.
 *   - createTracked(...) new tracked path (channel + coordinates + initial
 *                        status history, fingerprint v2). It is NOT wired to any
 *                        route or controller yet.
 *
 * DORMANT: no route or controller references createTracked(). The canonical
 * creation endpoint remains `POST /api/mobile/v1/requests`, which still calls
 * create(). Nothing here is activated by this step and no email/notification
 * side effect is performed.
 *
 * Legacy contract:
 *   create(AppUser $user, int $vehicleId, int $driverId, array $serviceIds, string $idempotencyKey)
 *
 * Tracked contract (temporary development boundary, C2):
 *   createTracked(AppUser $user, int $vehicleId, int $driverId, array $serviceIds,
 *                 string $channel, float $latitude, float $longitude, string $idempotencyKey)
 *
 * The authenticated AppUser, a validated vehicle id, a validated driver id, a
 * non-empty list of service ids, an approved channel, request-time coordinates
 * and a validated UUIDv4 idempotency key are the ONLY inputs. Arbitrary
 * `userId`/`status`/`platform` values are never accepted from the caller.
 *
 * Result shape (structured data, never an HTTP response):
 *   ['request' => ServiceRequest, 'wasCreated' => bool, 'wasReplayed' => bool]
 *
 * Failure modes are signalled with explicit exceptions so a future
 * controller can map them without this service knowing about HTTP:
 *   - InvalidArgumentException  … malformed idempotency key, service ids,
 *                                 channel or coordinates
 *   - IdempotencyConflictException … same key reused with a different payload
 *   - RuntimeException          … inconsistent record / retries exhausted
 *   - ValidationException       … propagated from the eligibility validator
 */
class RequestCreationService
{
    /**
     * Cleanup hint window stored on each idempotency row. Expiration never
     * authorises creating another request while the row still exists.
     */
    public const RETENTION_HOURS = 24;

    /**
     * Bound on both the framework's concurrency retry and this service's
     * same-key collision retry.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * The only channels approved for a tracked request. Matching is exact
     * (case-sensitive, no trimming) so a malformed channel can never be folded
     * into a valid request identity.
     *
     * @var list<string>
     */
    private const TRACKED_CHANNELS = ['app', 'whatsapp', 'phone'];

    public function __construct(
        private readonly RequestEligibilityValidator $eligibility,
        private readonly RequestStatusHistoryRecorder $historyRecorder,
    ) {}

    /**
     * Create (or replay) one logical mobile request on the legacy v1 path.
     *
     * Behaviour is intentionally unchanged: v1 fingerprint, `active` status,
     * no channel/coordinate writes and no status history.
     *
     * @param  array<int|string, mixed>  $serviceIds
     * @return array{request: ServiceRequest, wasCreated: bool, wasReplayed: bool}
     */
    public function create(
        AppUser $user,
        int $vehicleId,
        int $driverId,
        array $serviceIds,
        string $idempotencyKey,
    ): array {
        // Reject malformed keys before any database work. The caller may also
        // pre-validate with normalizeKey(); this is a defensive back-stop.
        $key = self::normalizeKey($idempotencyKey);

        // Rejects empty/duplicate/invalid service ids before any database work.
        $serviceIds = RequestFingerprint::normalizeServiceIds($serviceIds);

        $fingerprint = RequestFingerprint::make((int) $user->id, $vehicleId, $driverId, $serviceIds);

        return $this->runWithRetries(
            (int) $user->id,
            $key,
            fn (): array => $this->attempt($user, $vehicleId, $driverId, $serviceIds, $key, $fingerprint),
        );
    }

    /**
     * Create (or replay) one logical tracked request.
     *
     * All input validation happens before any database work. A genuinely new
     * request persists the channel, the request-time coordinate snapshot, the
     * derived initial status, the services pivot, the initial status history and
     * the idempotency claim in one transaction. Replays never re-check
     * eligibility, never write history and never modify the stored claim.
     *
     * @param  array<int|string, mixed>  $serviceIds
     * @return array{request: ServiceRequest, wasCreated: bool, wasReplayed: bool}
     */
    public function createTracked(
        AppUser $user,
        int $vehicleId,
        int $driverId,
        array $serviceIds,
        string $channel,
        float $latitude,
        float $longitude,
        string $idempotencyKey,
    ): array {
        // The caller must supply a persisted, authenticated AppUser. This
        // service cannot re-authenticate, but it rejects an unsaved model
        // before any claim lookup or database write.
        if (! $user->exists || $user->getKey() === null) {
            throw new InvalidArgumentException('An authenticated AppUser is required.');
        }

        // Every incoming tracked-contract value is validated before any claim
        // lookup or database write, including on the replay path.
        $key = self::normalizeKey($idempotencyKey);
        $serviceIds = RequestFingerprint::normalizeServiceIds($serviceIds);
        $channel = self::normalizeChannel($channel);
        self::assertCoordinates($latitude, $longitude);

        $fingerprint = RequestFingerprint::makeV2(
            (int) $user->id,
            $vehicleId,
            $driverId,
            $serviceIds,
            $channel,
        );

        // Only needed to verify pre-existing legacy claims during replay.
        $legacyFingerprint = RequestFingerprint::make(
            (int) $user->id,
            $vehicleId,
            $driverId,
            $serviceIds,
        );

        return $this->runWithRetries(
            (int) $user->id,
            $key,
            fn (): array => $this->attemptTracked(
                $user,
                $vehicleId,
                $driverId,
                $serviceIds,
                $channel,
                $latitude,
                $longitude,
                $key,
                $fingerprint,
                $legacyFingerprint,
            ),
        );
    }

    /**
     * Run one attempt inside the framework's transaction/retry wrapper.
     *
     * A unique-constraint violation is retried only when a committed claim for
     * this owner+key now exists; any other integrity error propagates.
     *
     * @param  callable(): array{request: ServiceRequest, wasCreated: bool, wasReplayed: bool}  $attempt
     * @return array{request: ServiceRequest, wasCreated: bool, wasReplayed: bool}
     */
    private function runWithRetries(int $appUserId, string $key, callable $attempt): array
    {
        $lastCollision = null;

        for ($i = 1; $i <= self::MAX_ATTEMPTS; $i++) {
            try {
                // The framework retries genuine deadlock / lock-wait /
                // serialization failures up to MAX_ATTEMPTS; a unique-constraint
                // violation is NOT a concurrency error and propagates immediately.
                return DB::transaction($attempt, self::MAX_ATTEMPTS);
            } catch (UniqueConstraintViolationException $e) {
                // Only a real same-key collision is retried. If no claim row for
                // this owner+key exists, the violation is an unrelated integrity
                // error and must not be swallowed.
                if (! $this->claimExists($appUserId, $key)) {
                    throw $e;
                }

                $lastCollision = $e;
                // Next iteration re-reads the committed row and replays it.
            }
        }

        throw new RuntimeException(
            'Unable to resolve the request idempotency key after '.self::MAX_ATTEMPTS.' attempts.',
            0,
            $lastCollision,
        );
    }

    /**
     * One legacy transactional attempt: replay an existing claim, or claim the
     * key and create the request atomically.
     *
     * @param  list<int>  $serviceIds
     * @return array{request: ServiceRequest, wasCreated: bool, wasReplayed: bool}
     */
    private function attempt(
        AppUser $user,
        int $vehicleId,
        int $driverId,
        array $serviceIds,
        string $key,
        string $fingerprint,
    ): array {
        $existing = $this->findClaim((int) $user->id, $key);

        if ($existing instanceof RequestIdempotencyKey) {
            return $this->replay($existing, $fingerprint);
        }

        // Eligibility is re-checked ONLY for new creation (fresh reads inside
        // the transaction). Replay never re-checks eligibility.
        $validated = $this->eligibility->validate([
            'vehicleId' => $vehicleId,
            'driverId' => $driverId,
            'services' => $serviceIds,
        ]);

        /** @var Driver $driver */
        $driver = $validated['driver'];

        $serviceRequest = ServiceRequest::query()->create([
            'platform' => 'mobile',
            'app_user_id' => $user->id,
            'driver_id' => $driver->id,
            'company_id' => $driver->company_id,
            'branch_id' => $driver->branch_id,
            'status' => 'active',
        ]);

        $serviceRequest->services()->sync($serviceIds);

        // Written last, with a concrete service_request_id (NOT NULL): there is
        // never an incomplete/processing row. The unique index on
        // (app_user_id, key) is the final concurrency guard.
        $this->storeClaim($user, $key, $fingerprint, $serviceRequest);

        return [
            'request' => $serviceRequest,
            'wasCreated' => true,
            'wasReplayed' => false,
        ];
    }

    /**
     * One tracked transactional attempt.
     *
     * The idempotency claim is written LAST, after the request, services pivot
     * and initial history, so any failure rolls the whole creation back.
     *
     * @param  list<int>  $serviceIds
     * @return array{request: ServiceRequest, wasCreated: bool, wasReplayed: bool}
     */
    private function attemptTracked(
        AppUser $user,
        int $vehicleId,
        int $driverId,
        array $serviceIds,
        string $channel,
        float $latitude,
        float $longitude,
        string $key,
        string $fingerprint,
        string $legacyFingerprint,
    ): array {
        $existing = $this->findClaim((int) $user->id, $key);

        if ($existing instanceof RequestIdempotencyKey) {
            return $this->replayTracked($existing, $fingerprint, $legacyFingerprint, $channel);
        }

        // Eligibility is re-checked ONLY for new creation (fresh reads inside
        // the transaction). Replay never re-checks eligibility.
        $validated = $this->eligibility->validate([
            'vehicleId' => $vehicleId,
            'driverId' => $driverId,
            'services' => $serviceIds,
        ]);

        /** @var Driver $driver */
        $driver = $validated['driver'];

        /** @var Vehicle $vehicle */
        $vehicle = $validated['vehicle'];

        $status = self::initialStatusForChannel($channel);

        $serviceRequest = ServiceRequest::query()->create([
            'platform' => 'mobile',
            'app_user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'company_id' => $driver->company_id,
            'branch_id' => $driver->branch_id,
            'channel' => $channel,
            'status' => $status,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        $serviceRequest->services()->sync($serviceIds);

        // Same transaction as the request: history commits or rolls back with
        // it. The recorder intentionally does not open its own transaction.
        $this->historyRecorder->recordInitial($serviceRequest, 'app_user', (int) $user->id);

        // Written last, with a concrete service_request_id (NOT NULL): there is
        // never an incomplete/processing row. The unique index on
        // (app_user_id, key) is the final concurrency guard.
        $this->storeClaim($user, $key, $fingerprint, $serviceRequest);

        return [
            'request' => $serviceRequest,
            'wasCreated' => true,
            'wasReplayed' => false,
        ];
    }

    /**
     * Replay an existing owner+key claim on the legacy v1 path.
     *
     * Never creates a request, never syncs services and never re-checks current
     * eligibility. The fingerprint is verified first; an expired-but-present row
     * still replays.
     *
     * @return array{request: ServiceRequest, wasCreated: bool, wasReplayed: bool}
     */
    private function replay(RequestIdempotencyKey $existing, string $fingerprint): array
    {
        if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
            throw new IdempotencyConflictException('The Idempotency-Key was already used with a different payload.');
        }

        // Soft-deleted or hard-deleted linked requests resolve to null here; we
        // fail explicitly rather than silently creating a duplicate.
        $request = $existing->serviceRequest()->first();

        if (! $request instanceof ServiceRequest) {
            throw new RuntimeException('The stored idempotency record has no resolvable request.');
        }

        return [
            'request' => $request,
            'wasCreated' => false,
            'wasReplayed' => true,
        ];
    }

    /**
     * Replay an existing owner+key claim on the tracked path.
     *
     * Resolution order is deliberate: verify the linked request first, then the
     * expected v2 hash. A pre-existing v1 hash is honoured ONLY for the legacy
     * app-channel pairing (incoming app + linked request app + exact v1 match).
     * The stored claim is never rewritten and no history row is added.
     *
     * @return array{request: ServiceRequest, wasCreated: bool, wasReplayed: bool}
     */
    private function replayTracked(
        RequestIdempotencyKey $existing,
        string $fingerprint,
        string $legacyFingerprint,
        string $channel,
    ): array {
        // Soft-deleted or hard-deleted linked requests resolve to null here; we
        // fail explicitly rather than silently creating a duplicate.
        $request = $existing->serviceRequest()->first();

        if (! $request instanceof ServiceRequest) {
            throw new RuntimeException('The stored idempotency record has no resolvable request.');
        }

        $stored = (string) $existing->request_fingerprint;

        // Current contract: constant-time comparison against the v2 hash.
        if (hash_equals($stored, $fingerprint)) {
            return $this->replayed($request);
        }

        // Legacy v1 claims: app channel only, and only when the linked request
        // itself is an app request. Never accepted for whatsapp or phone.
        if (
            $channel === 'app'
            && (string) $request->channel === 'app'
            && hash_equals($stored, $legacyFingerprint)
        ) {
            return $this->replayed($request);
        }

        throw new IdempotencyConflictException('The Idempotency-Key was already used with a different payload.');
    }

    /**
     * @return array{request: ServiceRequest, wasCreated: bool, wasReplayed: bool}
     */
    private function replayed(ServiceRequest $request): array
    {
        return [
            'request' => $request,
            'wasCreated' => false,
            'wasReplayed' => true,
        ];
    }

    private function findClaim(int $appUserId, string $key): ?RequestIdempotencyKey
    {
        return RequestIdempotencyKey::query()
            ->where('app_user_id', $appUserId)
            ->where('key', $key)
            ->first();
    }

    private function storeClaim(
        AppUser $user,
        string $key,
        string $fingerprint,
        ServiceRequest $serviceRequest,
    ): RequestIdempotencyKey {
        return RequestIdempotencyKey::query()->create([
            'app_user_id' => $user->id,
            'key' => $key,
            'request_fingerprint' => $fingerprint,
            'service_request_id' => $serviceRequest->id,
            'expires_at' => now()->addHours(self::RETENTION_HOURS),
        ]);
    }

    /**
     * Whether a claim row for this owner+key is now committed. Used to tell a
     * genuine same-key collision apart from an unrelated integrity error.
     */
    private function claimExists(int $appUserId, string $key): bool
    {
        return RequestIdempotencyKey::query()
            ->where('app_user_id', $appUserId)
            ->where('key', $key)
            ->exists();
    }

    /**
     * Validate an approved tracked channel with an exact, case-sensitive
     * comparison. Never trims or normalises the incoming value.
     *
     * @throws InvalidArgumentException
     */
    private static function normalizeChannel(string $channel): string
    {
        if (! in_array($channel, self::TRACKED_CHANNELS, true)) {
            throw new InvalidArgumentException('Channel must be one of: app, whatsapp, phone.');
        }

        return $channel;
    }

    /**
     * The derived initial status for a tracked request.
     */
    private static function initialStatusForChannel(string $channel): string
    {
        return $channel === 'app' ? 'pending' : 'contact_initiated';
    }

    /**
     * Reject missing/invalid/out-of-range coordinates before any database work.
     *
     * Individual zero values are valid; only the (0, 0) pair is rejected.
     *
     * @throws InvalidArgumentException
     */
    private static function assertCoordinates(float $latitude, float $longitude): void
    {
        if (! is_finite($latitude) || $latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException('Latitude must be a finite number between -90 and 90.');
        }

        if (! is_finite($longitude) || $longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException('Longitude must be a finite number between -180 and 180.');
        }

        if ($latitude == 0.0 && $longitude == 0.0) {
            throw new InvalidArgumentException('Latitude and longitude cannot both be zero.');
        }
    }

    /**
     * Normalize and validate a client-supplied `Idempotency-Key`.
     *
     * Required, trimmed and lowercased. Must be a syntactically valid UUID v4.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeKey(string $key): string
    {
        $normalized = strtolower(trim($key));

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $normalized) !== 1) {
            throw new InvalidArgumentException('The Idempotency-Key must be a valid UUID v4.');
        }

        return $normalized;
    }
}
