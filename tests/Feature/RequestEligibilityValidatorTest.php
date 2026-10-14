<?php

namespace Tests\Feature;

use App\Services\RequestEligibilityValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.2C.1 — isolated verification of RequestEligibilityValidator against the
 * real Eloquent relationships (synthetic fixtures only).
 */
class RequestEligibilityValidatorTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    private function validator(): RequestEligibilityValidator
    {
        return new RequestEligibilityValidator();
    }

    /**
     * Assert that validating $input raises a ValidationException carrying at
     * least one error under $prefix.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertValidationError(array $input, string $prefix): void
    {
        try {
            $this->validator()->validate($input);
            $this->fail("Expected a ValidationException under `{$prefix}`.");
        } catch (ValidationException $exception) {
            $keys = array_keys($exception->errors());
            $matches = array_filter($keys, fn (string $key): bool => str_starts_with($key, $prefix));
            $this->assertNotEmpty(
                $matches,
                "Expected a validation error under `{$prefix}`; got: ".implode(', ', $keys),
            );
        }
    }

    public function test_valid_online_vehicle_with_assigned_driver_and_offered_service_passes(): void
    {
        $fixture = $this->makeRequestFixture(1);

        $result = $this->validator()->validate([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => [$fixture['services'][0]->id],
        ]);

        $this->assertSame($fixture['vehicle']->id, $result['vehicle']->id);
        $this->assertSame($fixture['driver']->id, $result['driver']->id);
        $this->assertCount(1, $result['services']);
        $this->assertSame($fixture['services'][0]->id, $result['services']->first()->id);
    }

    public function test_multiple_offered_services_are_all_returned(): void
    {
        $fixture = $this->makeRequestFixture(3);

        $ids = array_map(fn ($service) => $service->id, $fixture['services']);

        $result = $this->validator()->validate([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => $ids,
        ]);

        $this->assertCount(3, $result['services']);
        $this->assertEqualsCanonicalizing($ids, $result['services']->pluck('id')->all());
    }

    public function test_empty_service_list_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);

        $this->assertValidationError([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => [],
        ], 'services');
    }

    public function test_duplicate_service_ids_are_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $serviceId = $fixture['services'][0]->id;

        $this->assertValidationError([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => [$serviceId, $serviceId],
        ], 'services');
    }

    public function test_driver_mismatch_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $otherDriver = $this->makeRequestFixture(1)['driver'];

        $this->assertValidationError([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $otherDriver->id,
            'services' => [$fixture['services'][0]->id],
        ], 'driverId');
    }

    public function test_service_not_offered_by_vehicle_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $unattached = $this->makeUnattachedService();

        $this->assertValidationError([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => [$unattached->id],
        ], 'services');
    }

    public function test_non_existent_service_id_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);

        $this->assertValidationError([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => [999999],
        ], 'services');
    }

    public function test_offline_vehicle_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1, vehicleOverrides: ['work_status' => 'offline']);

        $this->assertValidationError([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => [$fixture['services'][0]->id],
        ], 'vehicleId');
    }

    public function test_vehicle_without_current_driver_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1, vehicleOverrides: ['current_driver_id' => null]);

        $this->assertValidationError([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => [$fixture['services'][0]->id],
        ], 'vehicleId');
    }

    public function test_missing_vehicle_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);

        $this->assertValidationError([
            'vehicleId' => 999999,
            'driverId' => $fixture['driver']->id,
            'services' => [$fixture['services'][0]->id],
        ], 'vehicleId');
    }

    public function test_soft_deleted_vehicle_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $fixture['vehicle']->delete();

        $this->assertValidationError([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => [$fixture['services'][0]->id],
        ], 'vehicleId');
    }

    public function test_missing_driver_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);

        $this->assertValidationError([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => 999999,
            'services' => [$fixture['services'][0]->id],
        ], 'driverId');
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $this->assertValidationError([], 'vehicleId');
    }
}
