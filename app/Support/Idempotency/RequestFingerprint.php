<?php

namespace App\Support\Idempotency;

use InvalidArgumentException;

/**
 * Canonical request fingerprint for the secured mobile v2 request creation
 * contract (B2.2B.3B.2).
 *
 * The fingerprint is a deterministic, lowercase SHA-256 hex digest of a
 * versioned canonical string:
 *
 *     v1|u:{appUserId}|v:{vehicleId}|d:{driverId}|s:{sortedServiceIds}
 *
 * Only the authenticated user id, the vehicle id, the driver id and the
 * requested service ids participate. Presentation/transport data (`platform`)
 * and lifecycle data (`status`) are intentionally excluded, and service order
 * never affects the result.
 *
 * Invalid or duplicate service ids are REJECTED here rather than silently
 * normalised, so a malformed payload can never be folded into a valid request
 * identity.
 */
final class RequestFingerprint
{
    /**
     * Canonical format version. Bump this when the input contract changes so a
     * new payload shape can never collide with an old fingerprint.
     */
    private const VERSION = 'v1';

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
}
