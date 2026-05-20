<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverParityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_driver_login_success_and_failure(): void
    {
        $this->createDriverFixture();

        $this->postJson('/api/driver/v1/auth/login', [
            'email' => 'driver.parity@example.com',
            'password' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['driver', 'accessToken']]);

        $this->postJson('/api/driver/v1/auth/login', [
            'email' => 'driver.parity@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_driver_profile_get_and_update(): void
    {
        $driver = $this->createDriverFixture();
        Sanctum::actingAs($driver, ['driver']);

        $this->getJson('/api/driver/v1/drivers/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'driver.parity@example.com');

        $this->putJson('/api/driver/v1/drivers/profile', [
            'name' => 'Updated Driver',
            'phoneNum' => '555000999',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Updated Driver')
            ->assertJsonPath('data.phoneNum', '555000999');
    }

    public function test_driver_vehicle_owner_location_status_link_and_unlink_flows(): void
    {
        $driver = $this->createDriverFixture();
        $vehicle = $this->createVehicleForDriver($driver);
        Sanctum::actingAs($driver, ['driver']);

        $this->getJson('/api/driver/v1/vehicles/owner')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $vehicle->id);

        $this->postJson('/api/driver/v1/vehicles/link')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workStatus', 'online')
            ->assertJsonPath('data.currentDriver.id', $driver->id);

        $this->putJson('/api/driver/v1/vehicles/location', [
            'location' => [
                'latitude' => 29.3759,
                'longitude' => 47.9774,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.latitude', '29.3759000')
            ->assertJsonPath('data.longitude', '47.9774000');

        $this->putJson('/api/driver/v1/vehicles/'.$vehicle->id, [
            'workStatus' => 'offline',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workStatus', 'offline');

        $this->postJson('/api/driver/v1/vehicles/unlink')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workStatus', 'offline')
            ->assertJsonPath('data.currentDriver', null);
    }

    public function test_driver_legacy_prefix_aliases_driver_routes(): void
    {
        $driver = $this->createDriverFixture();
        $vehicle = $this->createVehicleForDriver($driver);
        Sanctum::actingAs($driver, ['driver']);

        $this->getJson('/api/driver-app/v1/vehicles/owner')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $vehicle->id);
    }

    public function test_wrong_area_token_is_rejected_from_driver_routes(): void
    {
        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($user, ['dashboard']);

        $this->getJson('/api/driver/v1/drivers/profile')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    private function createDriverFixture(): Driver
    {
        $company = Company::query()->create([
            'name_en' => 'Driver Parity Company',
            'name_ar' => 'Driver Parity Company',
            'email' => 'driver-parity-company@example.com',
            'app_code' => 'COM-DRIVER-PARITY',
        ]);

        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'name' => 'Driver Parity Branch',
            'app_code' => 'BR-DRIVER-PARITY',
        ]);

        return Driver::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Driver Parity',
            'email' => 'driver.parity@example.com',
            'phone_num' => '555000111',
            'password' => '123456',
            'status' => 'active',
        ]);
    }

    private function createVehicleForDriver(Driver $driver): Vehicle
    {
        $vehicle = Vehicle::query()->create([
            'company_id' => $driver->company_id,
            'branch_id' => $driver->branch_id,
            'app_code' => 'VEH-DRIVER-PARITY',
            'status' => 'active',
            'work_status' => 'offline',
        ]);

        $vehicle->drivers()->attach($driver->id);
        $driver->update(['vehicle_id' => $vehicle->id]);

        return $vehicle;
    }
}
