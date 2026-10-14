<?php

namespace App\Services;

use App\Models\AppUser;
use App\Models\RequestIdempotencyKey;
use App\Models\ServiceRequest;
use App\Support\Idempotency\RequestFingerprint;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Atomic, idempotent ServiceRequest creation for the secured mobile v2 contract
 * (B2.2B.3B.2).
 *
 * DORMANT: this class is intentionally NOT referenced by any route or
 * controller yet. `POST /api/mobile/v2/requests` still returns 503 NOT_ENABLED.
 * It is written so a later, explicitly approved step can wire it in; nothing
 * here is executed during this step, and no email/notification side effect is
 * performed (that is B2.2B.3B.3).
 *
 * Contract:
 *   create(AppUser $user, int $vehicleId, int $driverId, array $serviceIds, string $idempotencyKey)
 *
 * The authenticated AppUser, a validated vehicle id, a validated driver id, a
 * non-empty list of service ids and a validated UUIDv4 idempotency key are the
 * ONLY inputs. Arbitrary `userId`/`status`/`platform` values are never accepted
 * from the caller.
 *
 * Result shape (structured data, never an HTTP response):
 *   ['request' => ServiceRequest, 'wasCreated' => bool, 'wasReplayed' => bool]
 *
 * Failure modes are signalled with standard SPL exceptions so a future
 * controller can map them without this service knowing about HTTP:
 *   - InvalidArgumentException  … malformed idempotency key or service ids
 *   - DomainException           … same key reused with a different payload
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

    public function __construct(private readonly RequestEligibilityValidator $eligibility) {}

    /**
     * Create (or replay) one logical mobile request.
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

        $lastCollision = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                // The framework retries genuine deadlock / lock-wait /
                // serialization failures up to MAX_ATTEMPTS; a unique-constraint
                // violation is NOT a concurrency error and propagates immediately.
                return DB::transaction(
                    fn (): array => $this->attempt($user, $vehicleId, $driverId, $serviceIds, $key, $fingerprint),
                    self::MAX_ATTEMPTS,
                );
            } catch (UniqueConstraintViolationException $e) {
                // Only a real same-key collision is retried. If no claim row for
                // this owner+key exists, the violation is an unrelated integrity
                // error and must not be swallowed.
                if (! $this->claimExists((int) $user->id, $key)) {
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
     * One transactional attempt: replay an existing claim, or claim the key and
     * create the request atomically.
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
        $existing = RequestIdempotencyKey::query()
            ->where('app_user_id', $user->id)
            ->where('key', $key)
            ->first();

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

        /** @var \App\Models\Driver $driver */
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
        RequestIdempotencyKey::query()->create([
            'app_user_id' => $user->id,
            'key' => $key,
            'request_fingerprint' => $fingerprint,
            'service_request_id' => $serviceRequest->id,
            'expires_at' => now()->addHours(self::RETENTION_HOURS),
        ]);

        return [
            'request' => $serviceRequest,
            'wasCreated' => true,
            'wasReplayed' => false,
        ];
    }

    /**
     * Replay an existing owner+key claim.
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
            throw new DomainException('The Idempotency-Key was already used with a different payload.');
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
