<?php

namespace Tests\Concerns;

use App\Models\AppUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Service;
use App\Models\Vehicle;

/**
 * Builds synthetic in-memory fixtures for the B2.2C.1 isolated verification of
 * the mobile v2 request services.
 *
 * Every value here is generated test data. Nothing reads existing application
 * or production data, and no seeder is used.
 */
trait CreatesRequestFixtures
{
    /**
     * Minimal, self-consistent company/branch/driver/vehicle/service graph.
     *
     * @param  array<string, mixed>  $vehicleOverrides
     * @param  array<string, mixed>  $driverOverrides
     * @return array{company: Company, branch: Branch, driver: Driver, vehicle: Vehicle, services: array<int, Service>}
     */
    protected function makeRequestFixture(
        int $serviceCount = 1,
        array $vehicleOverrides = [],
        array $driverOverrides = [],
    ): array {
        $company = Company::query()->create([
            'name_en' => 'B2 Test Co',
            'name_ar' => 'B2 Test Co',
        ]);

        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'name' => 'B2 Test Branch',
        ]);

        $services = [];
        for ($i = 1; $i <= $serviceCount; $i++) {
            $services[] = Service::query()->create([
                'name_en' => 'B2 Service '.$i,
                'name_ar' => 'B2 Service '.$i,
                'status' => 'active',
            ]);
        }

        $driver = Driver::query()->create(array_merge([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'B2 Driver',
            'email' => 'b2.driver.'.uniqid('', true).'@example.com',
            'password' => '123456',
            'status' => 'active',
        ], $driverOverrides));

        $vehicle = Vehicle::query()->create(array_merge([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'current_driver_id' => $driver->id,
            'status' => 'active',
            'work_status' => 'online',
        ], $vehicleOverrides));

        foreach ($services as $service) {
            $vehicle->services()->attach($service->id);
        }

        return compact('company', 'branch', 'driver', 'vehicle', 'services');
    }

    /**
     * A standalone service that is NOT attached to any vehicle fixture.
     */
    protected function makeUnattachedService(): Service
    {
        return Service::query()->create([
            'name_en' => 'B2 Unattached Service',
            'name_ar' => 'B2 Unattached Service',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeAppUser(array $overrides = []): AppUser
    {
        return AppUser::query()->create(array_merge([
            'name' => 'B2 App User',
            'email' => 'b2.user.'.uniqid('', true).'@example.com',
            'password' => '123456',
            'is_email_verified' => true,
            'status' => 'active',
        ], $overrides));
    }
}
