<?php

namespace Tests\Unit;

use App\Support\Idempotency\RequestFingerprint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionParameter;
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

    // ---------------------------------------------------------------------
    // B2.5C/C1 — additive v2 (channel-aware) fingerprint coverage.
    // Legacy v1 assertions above are intentionally left untouched.
    // ---------------------------------------------------------------------

    /**
     * A. Existing v1 canonical literal unchanged.
     */
    public function test_legacy_v1_canonical_literal_is_unchanged(): void
    {
        $this->assertSame('v1|u:1|v:2|d:3|s:1,2', RequestFingerprint::canonical(1, 2, 3, [2, 1]));
    }

    /**
     * B. Existing v1 SHA-256 fingerprint unchanged.
     */
    public function test_legacy_v1_digest_is_unchanged(): void
    {
        $this->assertSame(
            hash('sha256', 'v1|u:1|v:2|d:3|s:1,2'),
            RequestFingerprint::make(1, 2, 3, [1, 2]),
        );
    }

    /**
     * C. v2 canonical literal follows exactly the approved format.
     */
    public function test_v2_canonical_literal_includes_channel(): void
    {
        $this->assertSame(
            'v2|u:1|v:2|d:3|s:1,2|c:app',
            RequestFingerprint::canonicalV2(1, 2, 3, [2, 1], 'app'),
        );
    }

    /**
     * D. v2 digest is lowercase 64-char SHA-256 of its canonical string.
     */
    public function test_v2_fingerprint_is_lowercase_sha256_hex(): void
    {
        $fingerprint = RequestFingerprint::makeV2(1, 2, 3, [1, 2], 'app');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        $this->assertSame(
            hash('sha256', RequestFingerprint::canonicalV2(1, 2, 3, [1, 2], 'app')),
            $fingerprint,
        );
    }

    /**
     * E. Service order does not change the v2 hash.
     */
    public function test_v2_service_order_does_not_change_fingerprint(): void
    {
        $this->assertSame(
            RequestFingerprint::makeV2(10, 20, 30, [3, 1, 2], 'app'),
            RequestFingerprint::makeV2(10, 20, 30, [1, 2, 3], 'app'),
        );
    }

    /**
     * F. Different channel changes the v2 hash.
     */
    public function test_v2_different_channel_changes_fingerprint(): void
    {
        $this->assertNotSame(
            RequestFingerprint::makeV2(10, 20, 30, [1], 'app'),
            RequestFingerprint::makeV2(10, 20, 30, [1], 'whatsapp'),
        );
        $this->assertNotSame(
            RequestFingerprint::makeV2(10, 20, 30, [1], 'whatsapp'),
            RequestFingerprint::makeV2(10, 20, 30, [1], 'phone'),
        );
    }

    /**
     * G. Same inputs and channel are deterministic.
     */
    public function test_v2_is_deterministic(): void
    {
        $this->assertSame(
            RequestFingerprint::makeV2(10, 20, 30, [1, 2, 3], 'phone'),
            RequestFingerprint::makeV2(10, 20, 30, [1, 2, 3], 'phone'),
        );
    }

    /**
     * H. v1 and v2 fingerprints differ for identical identity inputs.
     */
    public function test_v1_and_v2_fingerprints_differ(): void
    {
        $this->assertNotSame(
            RequestFingerprint::make(10, 20, 30, [1, 2]),
            RequestFingerprint::makeV2(10, 20, 30, [1, 2], 'app'),
        );
    }

    /**
     * I. Each identity component affects the v2 fingerprint.
     */
    public function test_v2_each_identity_component_changes_fingerprint(): void
    {
        $base = RequestFingerprint::makeV2(10, 20, 30, [1], 'app');

        $this->assertNotSame($base, RequestFingerprint::makeV2(11, 20, 30, [1], 'app'));
        $this->assertNotSame($base, RequestFingerprint::makeV2(10, 21, 30, [1], 'app'));
        $this->assertNotSame($base, RequestFingerprint::makeV2(10, 20, 31, [1], 'app'));
        $this->assertNotSame($base, RequestFingerprint::makeV2(10, 20, 30, [2], 'app'));
    }

    /**
     * J. Unknown, blank, uppercase and whitespace-padded channels are rejected.
     *
     * @return array<string, array{string}>
     */
    public static function rejectedChannels(): array
    {
        return [
            'unknown' => ['email'],
            'blank' => [''],
            'uppercase' => ['APP'],
            'mixed case' => ['WhatsApp'],
            'padded' => [' app '],
            'padded whatsapp' => ['whatsapp '],
        ];
    }

    #[DataProvider('rejectedChannels')]
    public function test_v2_rejects_invalid_channels(string $channel): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::canonicalV2(10, 20, 30, [1], $channel);
    }

    /**
     * K. Empty service ids remain rejected in v2.
     */
    public function test_v2_rejects_empty_service_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::canonicalV2(10, 20, 30, [], 'app');
    }

    /**
     * K. Duplicate service ids remain rejected in v2.
     */
    public function test_v2_rejects_duplicate_service_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::canonicalV2(10, 20, 30, [1, 1], 'app');
    }

    /**
     * K. Non-positive service ids remain rejected in v2.
     */
    public function test_v2_rejects_non_positive_service_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::canonicalV2(10, 20, 30, [0], 'app');
    }

    /**
     * K. Non-integer service ids remain rejected in v2.
     */
    public function test_v2_rejects_non_integer_service_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::canonicalV2(10, 20, 30, ['abc'], 'app');
    }

    /**
     * K. Float service ids remain rejected in v2.
     */
    public function test_v2_rejects_float_service_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestFingerprint::canonicalV2(10, 20, 30, [1.5], 'app');
    }

    /**
     * L. Legacy v1 builder is still usable independently after v2 exists.
     */
    public function test_legacy_v1_builder_remains_usable_alongside_v2(): void
    {
        $this->assertSame('v1|u:1|v:2|d:3|s:1,2', RequestFingerprint::canonical(1, 2, 3, [1, 2]));
        $this->assertSame(
            hash('sha256', 'v1|u:1|v:2|d:3|s:1,2'),
            RequestFingerprint::make(1, 2, 3, [1, 2]),
        );
        $this->assertSame(
            'v2|u:1|v:2|d:3|s:1,2|c:app',
            RequestFingerprint::canonicalV2(1, 2, 3, [1, 2], 'app'),
        );
    }

    /**
     * M. No coordinates participate in the v2 canonical format or inputs.
     */
    public function test_v2_canonical_contains_no_coordinate_data(): void
    {
        $canonical = RequestFingerprint::canonicalV2(1, 2, 3, [1, 2], 'app');

        $this->assertSame('v2|u:1|v:2|d:3|s:1,2|c:app', $canonical);
        $this->assertStringNotContainsString('lat', $canonical);
        $this->assertStringNotContainsString('lon', $canonical);

        $parameterNames = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(RequestFingerprint::class, 'makeV2'))->getParameters(),
        );

        $this->assertSame(
            ['appUserId', 'vehicleId', 'driverId', 'serviceIds', 'channel'],
            $parameterNames,
        );
    }
}
