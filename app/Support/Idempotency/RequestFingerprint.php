<?php

namespace App\Support\Idempotency;

use InvalidArgumentException;

/**
 * Canonical request fingerprint for the secured mobile request creation
 * contract (B2.2B.3B.2, extended additively in B2.5C/C1).
 *
 * The fingerprint is a deterministic, lowercase SHA-256 hex digest of a
 * versioned canonical string.
 *
 * Legacy v1 (unchanged — still produced by make()/canonical()):
 *
 *     v1|u:{appUserId}|v:{vehicleId}|d:{driverId}|s:{sortedServiceIds}
 *
 * Current v2 (channel-aware — produced by makeV2()/canonicalV2()):
 *
 *     v2|u:{appUserId}|v:{vehicleId}|d:{driverId}|s:{sortedServiceIds}|c:{channel}
 *
 * Only the authenticated user id, the vehicle id, the driver id, the requested
 * service ids and (in v2) the channel participate. Presentation/transport data
 * (`platform`) and lifecycle data (`status`) are intentionally excluded, and
 * service order never affects the result. Customer coordinates are deliberately
 * excluded from both versions: GPS jitter must never change attempt identity.
 *
 * v1 and v2 are separate explicit builders. The v1 format is preserved so
 * already-persisted claims can still be verified during the future dual-version
 * replay step; nothing here switches versions automatically.
 *
 * Invalid or duplicate service ids are REJECTED here rather than silently
 * normalised, so a malformed payload can never be folded into a valid request
 * identity. Unknown, blank, incorrectly-cased or whitespace-padded channels are
 * rejected the same way.
 */
final class RequestFingerprint
{
    /**
     * Legacy canonical format version, retained verbatim. Bump this when the
     * input contract changes so a new payload shape can never collide with an
     * old fingerprint.
     */
    private const VERSION = 'v1';

    /**
     * Channel-aware canonical format version introduced in B2.5C/C1.
     */
    private const VERSION_V2 = 'v2';

    /**
     * The only channels approved for a tracked request. Matching is exact
     * (case-sensitive, no trimming) so a malformed channel can never be folded
     * into a valid request identity.
     *
     * @var list<string>
     */
    private const CHANNELS_V2 = ['app', 'whatsapp', 'phone'];

    /**
     * Deterministic fingerprint for one logical mobile request.
     *
     * @param  array<int|string, mixed>  $serviceIds
     */
    public static function make(int $appUserId, int $vehicleId, int $driverId, array $serviceIds): string
    {
        return hash('sha256', self::canonical($appUserId, $vehicleId, $driverId, $serviceIds));
    }

    /**
     * Build the versioned canonical string that is hashed.
     *
     * @param  array<int|string, mixed>  $serviceIds
     */
    public static function canonical(int $appUserId, int $vehicleId, int $driverId, array $serviceIds): string
    {
        return sprintf(
            '%s|u:%d|v:%d|d:%d|s:%s',
            self::VERSION,
            $appUserId,
            $vehicleId,
            $driverId,
            implode(',', self::normalizeServiceIds($serviceIds)),
        );
    }

    /**
     * Deterministic channel-aware fingerprint (v2) for one logical request.
     *
     * Coordinates are intentionally not accepted: only identity fields plus the
     * channel participate, so moving GPS coordinates never change the result.
     *
     * @param  array<int|string, mixed>  $serviceIds
     */
    public static function makeV2(
        int $appUserId,
        int $vehicleId,
        int $driverId,
        array $serviceIds,
        string $channel,
    ): string {
        return hash('sha256', self::canonicalV2($appUserId, $vehicleId, $driverId, $serviceIds, $channel));
    }

    /**
     * Build the channel-aware (v2) canonical string that is hashed.
     *
     * @param  array<int|string, mixed>  $serviceIds
     */
    public static function canonicalV2(
        int $appUserId,
        int $vehicleId,
        int $driverId,
        array $serviceIds,
        string $channel,
    ): string {
        return sprintf(
            '%s|u:%d|v:%d|d:%d|s:%s|c:%s',
            self::VERSION_V2,
            $appUserId,
            $vehicleId,
            $driverId,
            implode(',', self::normalizeServiceIds($serviceIds)),
            self::normalizeChannel($channel),
        );
    }

    /**
     * Normalize service ids to a sorted, de-duplication-free list of positive
     * integers.
     *
     * Rejects (never silently fixes) empty input, non-integer ids, non-positive
     * ids and duplicate ids.
     *
     * @param  array<int|string, mixed>  $serviceIds
     * @return list<int>
     */
    public static function normalizeServiceIds(array $serviceIds): array
    {
        if ($serviceIds === []) {
            throw new InvalidArgumentException('At least one service id is required.');
        }

        $normalized = [];

        foreach ($serviceIds as $serviceId) {
            if (is_int($serviceId)) {
                $value = $serviceId;
            } elseif (is_string($serviceId) && preg_match('/^[0-9]+$/', $serviceId) === 1) {
                $value = (int) $serviceId;
            } else {
                throw new InvalidArgumentException('Service ids must be positive integers.');
            }

            if ($value <= 0) {
                throw new InvalidArgumentException('Service ids must be positive integers.');
            }

            $normalized[] = $value;
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new InvalidArgumentException('Duplicate service ids are not allowed.');
        }

        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /**
     * Validate an approved v2 channel with an exact, case-sensitive comparison.
     *
     * Rejects (never normalises) unknown, blank, uppercase, mixed-case or
     * whitespace-padded values.
     */
    private static function normalizeChannel(string $channel): string
    {
        if (! in_array($channel, self::CHANNELS_V2, true)) {
            throw new InvalidArgumentException('Channel must be one of: app, whatsapp, phone.');
        }

        return $channel;
    }
}
