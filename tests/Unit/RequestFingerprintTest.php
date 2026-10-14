<?php

namespace Tests\Unit;

use App\Support\Idempotency\RequestFingerprint;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * B2.2C.1 — DB-independent unit verification of the request fingerprint.
 *
 * These assertions exercise only pure static logic; no database is touched.
 */
class RequestFingerprintTest extends TestCase
{
    public function test_canonical_string_is_versioned_and_sorted(): void
    {
        $this->assertSame('v1|u:1|v:2|d:3|s:1,2', RequestFingerprint::canonical(1, 2, 3, [2, 1]));
    }

    public function test_fingerprint_is_lowercase_sha256_hex(): void
    {
        $fingerprint = RequestFingerprint::make(1, 2, 3, [1, 2]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        $this->assertSame(hash('sha256', RequestFingerprint::canonical(1, 2, 3, [1, 2])), $fingerprint);
    }

    public function test_same_ids_produce_same_fingerprint(): void
    {
        $this->assertSame(
            RequestFingerprint::make(10, 20, 30, [1, 2, 3]),
            RequestFingerprint::make(10, 20, 30, [1, 2, 3]),
        );
    }

    public function test_service_order_does_not_change_fingerprint(): void
    {
        $this->assertSame(
            RequestFingerprint::make(10, 20, 30, [3, 1, 2]),
            RequestFingerprint::make(10, 20, 30, [1, 2, 3]),
        );
    }

    public function test_numeric_string_service_ids_are_normalized(): void
    {
        $this->assertSame(
            RequestFingerprint::make(10, 20, 30, ['2', 1]),
            RequestFingerprint::make(10, 20, 30, [1, 2]),
        );
    }

    public function test_different_user_changes_fingerprint(): void
    {
        $this->assertNotSame(
            RequestFingerprint::make(1, 20, 30, [1]),
            RequestFingerprint::make(2, 20, 30, [1]),
        );
    }

    public function test_different_vehicle_changes_fingerprint(): void
    {
        $this->assertNotSame(
            RequestFingerprint::make(10, 20, 30, [1]),
            RequestFingerprint::make(10, 21, 30, [1]),
        );
    }

    public function test_different_driver_changes_fingerprint(): void
    {
        $this->assertNotSame(
            RequestFingerprint::make(10, 20, 30, [1]),
            RequestFingerprint::make(10, 20, 31, [1]),
        );
    }

    public function test_different_service_selection_changes_fingerprint(): void
    {
        $this->assertNotSame(
            RequestFingerprint::make(10, 20, 30, [1]),
            RequestFingerprint::make(10, 20, 30, [2]),
        );
    }

    public function test_empty_service_ids_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::make(10, 20, 30, []);
    }

    public function test_duplicate_service_ids_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::normalizeServiceIds([1, 1]);
    }

    public function test_non_integer_service_ids_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::normalizeServiceIds(['abc']);
    }

    public function test_float_service_ids_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::normalizeServiceIds([1.5]);
    }

    public function test_null_service_ids_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::normalizeServiceIds([null]);
    }

    public function test_zero_and_negative_service_ids_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::normalizeServiceIds([0]);
    }

    public function test_negative_service_ids_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::normalizeServiceIds([-3]);
    }

    public function test_normalize_returns_sorted_positive_integers(): void
    {
        $this->assertSame([1, 2, 10], RequestFingerprint::normalizeServiceIds(['10', 2, 1]));
    }
}
