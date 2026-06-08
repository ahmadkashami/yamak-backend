<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\Branch;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Driver;
use App\Models\State;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Validates the Address and Geolocation Schema Strategy acceptance criteria:
 *  - Lat/lon stored as explicit decimal columns (not JSON)
 *  - Composite indexes exist on (latitude, longitude) for location-bearing tables
 *  - Address fields mapped to explicit columns on branches
 *  - geoLocation envelope emitted with correct coordinate order [longitude, latitude]
 *  - Mobile vehicle lookup accepts lat/lon query params for distance sorting
 *  - Driver location update returns the geoLocation envelope
 */
class GeoLocationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    // ─── Schema ───────────────────────────────────────────────────────────────

    public function test_geolocation_indexes_exist_on_vehicles(): void
    {
        $this->assertTrue(
            Schema::hasColumn('vehicles', 'latitude'),
            'vehicles.latitude column missing'
        );
        $this->assertTrue(
            Schema::hasColumn('vehicles', 'longitude'),
            'vehicles.longitude column missing'
        );

        $indexes = collect(Schema::getIndexes('vehicles'))->pluck('name');
        $this->assertTrue(
            $indexes->contains('vehicles_geo_idx'),
            'Composite geo index vehicles_geo_idx missing from vehicles table'
        );
    }

    public function test_geolocation_indexes_exist_on_app_users(): void
    {
        $this->assertTrue(Schema::hasColumn('app_users', 'latitude'));
        $this->assertTrue(Schema::hasColumn('app_users', 'longitude'));

        $indexes = collect(Schema::getIndexes('app_users'))->pluck('name');
        $this->assertTrue(
            $indexes->contains('app_users_geo_idx'),
            'Composite geo index app_users_geo_idx missing from app_users table'
        );
    }

    public function test_geolocation_indexes_exist_on_branches(): void
    {
        $this->assertTrue(Schema::hasColumn('branches', 'latitude'));
        $this->assertTrue(Schema::hasColumn('branches', 'longitude'));

        $indexes = collect(Schema::getIndexes('branches'))->pluck('name');
        $this->assertTrue(
            $indexes->contains('branches_geo_idx'),
            'Composite geo index branches_geo_idx missing from branches table'
        );
    }

    public function test_branches_table_has_all_address_columns(): void
    {
        foreach (['country_id', 'state_id', 'city_id', 'block', 'street', 'zip_code'] as $col) {
            $this->assertTrue(Schema::hasColumn('branches', $col), "branches.{$col} missing");
        }
    }

    // ─── Model round-trip ─────────────────────────────────────────────────────

    public function test_vehicle_stores_and_retrieves_lat_lon_as_decimals(): void
    {
        [$company, $branch] = $this->makeCompanyBranch();

        $vehicle = Vehicle::query()->create([
            'company_id' => $company->id,
            'branch_id'  => $branch->id,
            'app_code'   => 'VEH-GEO-1',
            'status'     => 'active',
            'work_status' => 'offline',
            'latitude'   => 29.3759,
            'longitude'  => 47.9774,
        ]);

        $fresh = Vehicle::query()->find($vehicle->id);

        $this->assertEqualsWithDelta(29.3759, (float) $fresh->latitude, 0.0001);
        $this->assertEqualsWithDelta(47.9774, (float) $fresh->longitude, 0.0001);
    }

    public function test_app_user_stores_and_retrieves_lat_lon_as_decimals(): void
    {
        $user = AppUser::query()->create([
            'email'    => 'geo@example.com',
            'password' => 'secret',
            'status'   => 'active',
            'latitude' => 29.3759,
            'longitude' => 47.9774,
        ]);

        $fresh = AppUser::query()->find($user->id);

        $this->assertEqualsWithDelta(29.3759, (float) $fresh->latitude, 0.0001);
        $this->assertEqualsWithDelta(47.9774, (float) $fresh->longitude, 0.0001);
    }

    public function test_branch_stores_and_retrieves_address_and_lat_lon(): void
    {
        [$company] = $this->makeCompanyBranch();
        [$country, $state, $city] = $this->makeCountryStateCity();

        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'name'       => 'Address Branch',
            'country_id' => $country->id,
            'state_id'   => $state->id,
            'city_id'    => $city->id,
            'block'      => 'Block 5',
            'street'     => 'Gulf Rd',
            'zip_code'   => '13001',
            'latitude'   => 29.3759,
            'longitude'  => 47.9774,
        ]);

        $fresh = Branch::query()->find($branch->id);

        $this->assertSame($country->id, $fresh->country_id);
        $this->assertSame($state->id, $fresh->state_id);
        $this->assertSame($city->id, $fresh->city_id);
        $this->assertSame('Block 5', $fresh->block);
        $this->assertSame('Gulf Rd', $fresh->street);
        $this->assertSame('13001', $fresh->zip_code);
        $this->assertEqualsWithDelta(29.3759, (float) $fresh->latitude, 0.0001);
        $this->assertEqualsWithDelta(47.9774, (float) $fresh->longitude, 0.0001);
    }

    // ─── API coordinate order ─────────────────────────────────────────────────

    public function test_vehicle_api_returns_geolocation_with_longitude_first_in_coordinates(): void
    {
        $admin = \App\Models\User::query()->where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin, ['dashboard']);

        [$company, $branch] = $this->makeCompanyBranch();

        $vehicle = Vehicle::query()->create([
            'company_id' => $company->id,
            'branch_id'  => $branch->id,
            'app_code'   => 'VEH-COORD',
            'status'     => 'active',
            'work_status' => 'offline',
            'latitude'   => 29.3759,
            'longitude'  => 47.9774,
        ]);

        $this->getJson('/api/dashboard/v1/vehicles/'.$vehicle->id)
            ->assertOk()
            ->assertJsonPath('data.geoLocation.latitude', 29.3759)
            ->assertJsonPath('data.geoLocation.longitude', 47.9774)
            // coordinates must be [longitude, latitude] — GeoJSON convention
            ->assertJsonPath('data.geoLocation.coordinates.0', 47.9774)
            ->assertJsonPath('data.geoLocation.coordinates.1', 29.3759);
    }

    public function test_branch_api_returns_address_envelope_with_all_fields(): void
    {
        $admin = \App\Models\User::query()->where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin, ['dashboard']);

        [$company] = $this->makeCompanyBranch();
        [$country, $state, $city] = $this->makeCountryStateCity();

        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'name'       => 'API Address Branch',
            'country_id' => $country->id,
            'state_id'   => $state->id,
            'city_id'    => $city->id,
            'block'      => 'B12',
            'street'     => 'Salem St',
            'zip_code'   => '99001',
            'latitude'   => 29.3759,
            'longitude'  => 47.9774,
        ]);

        $this->getJson('/api/dashboard/v1/branches/'.$branch->id)
            ->assertOk()
            ->assertJsonPath('data.address.countryId', (string) $country->id)
            ->assertJsonPath('data.address.stateId', (string) $state->id)
            ->assertJsonPath('data.address.cityId', (string) $city->id)
            ->assertJsonPath('data.address.block', 'B12')
            ->assertJsonPath('data.address.street', 'Salem St')
            ->assertJsonPath('data.address.zipCode', '99001')
            ->assertJsonPath('data.geoLocation.latitude', 29.3759)
            ->assertJsonPath('data.geoLocation.longitude', 47.9774);
    }

    // ─── Mobile vehicle lookup with distance ──────────────────────────────────

    public function test_mobile_vehicle_lookup_distance_sorts_by_proximity(): void
    {
        $appUser = AppUser::query()->create([
            'email'    => 'mobile.geo@example.com',
            'password' => 'secret',
            'status'   => 'active',
        ]);
        Sanctum::actingAs($appUser, ['mobile']);

        [$company, $branch] = $this->makeCompanyBranch();

        $driver1 = $this->makeDriver($company, $branch, 'geo-d1@example.com');
        $driver2 = $this->makeDriver($company, $branch, 'geo-d2@example.com');

        // Vehicle close to query point (29.3, 47.9)
        $close = Vehicle::query()->create([
            'company_id'       => $company->id,
            'branch_id'        => $branch->id,
            'current_driver_id' => $driver1->id,
            'app_code'         => 'VEH-CLOSE',
            'status'           => 'active',
            'work_status'      => 'online',
            'latitude'         => 29.31,
            'longitude'        => 47.91,
        ]);
        $close->drivers()->attach($driver1->id);

        // Vehicle far from query point
        $far = Vehicle::query()->create([
            'company_id'       => $company->id,
            'branch_id'        => $branch->id,
            'current_driver_id' => $driver2->id,
            'app_code'         => 'VEH-FAR',
            'status'           => 'active',
            'work_status'      => 'online',
            'latitude'         => 30.0,
            'longitude'        => 48.5,
        ]);
        $far->drivers()->attach($driver2->id);

        $response = $this->getJson('/api/mobile/v1/vehicles?latitude=29.3&longitude=47.9&sort=asc')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data.list'))->pluck('id')->all();

        $this->assertSame([$close->id, $far->id], $ids, 'Vehicles should be sorted closest first');
    }

    // ─── Driver location update ───────────────────────────────────────────────

    public function test_driver_location_update_returns_geolocation_envelope(): void
    {
        [$company, $branch] = $this->makeCompanyBranch();
        $driver = $this->makeDriver($company, $branch, 'loc-update@example.com');

        $vehicle = Vehicle::query()->create([
            'company_id'       => $company->id,
            'branch_id'        => $branch->id,
            'current_driver_id' => $driver->id,
            'app_code'         => 'VEH-LOC-UPD',
            'status'           => 'active',
            'work_status'      => 'online',
        ]);
        $vehicle->drivers()->attach($driver->id);
        $driver->update(['vehicle_id' => $vehicle->id]);

        Sanctum::actingAs($driver, ['driver']);

        $this->putJson('/api/driver/v1/vehicles/location', [
            'location' => ['latitude' => 29.3759, 'longitude' => 47.9774],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.geoLocation.latitude', 29.3759)
            ->assertJsonPath('data.geoLocation.longitude', 47.9774)
            // coordinates order: [longitude, latitude]
            ->assertJsonPath('data.geoLocation.coordinates.0', 47.9774)
            ->assertJsonPath('data.geoLocation.coordinates.1', 29.3759);
    }

    public function test_driver_location_update_also_accepts_flat_lat_lon_body(): void
    {
        [$company, $branch] = $this->makeCompanyBranch();
        $driver = $this->makeDriver($company, $branch, 'loc-flat@example.com');

        $vehicle = Vehicle::query()->create([
            'company_id'       => $company->id,
            'branch_id'        => $branch->id,
            'current_driver_id' => $driver->id,
            'app_code'         => 'VEH-LOC-FLAT',
            'status'           => 'active',
            'work_status'      => 'online',
        ]);
        $vehicle->drivers()->attach($driver->id);
        $driver->update(['vehicle_id' => $vehicle->id]);

        Sanctum::actingAs($driver, ['driver']);

        $this->putJson('/api/driver/v1/vehicles/location', [
            'latitude' => 29.3800, 'longitude' => 47.9800,
        ])
            ->assertOk()
            ->assertJsonPath('data.geoLocation.latitude', 29.38)
            ->assertJsonPath('data.geoLocation.longitude', 47.98);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeCountryStateCity(): array
    {
        $country = Country::query()->create([
            'name_en' => 'Kuwait',
            'name_ar' => 'الكويت',
            'country_code' => 'KW'.uniqid(),
            'currency' => 'KWD',
        ]);

        $state = State::query()->create([
            'country_id' => $country->id,
            'name_en' => 'Capital',
            'name_ar' => 'العاصمة',
        ]);

        $city = City::query()->create([
            'state_id' => $state->id,
            'name_en' => 'Kuwait City',
            'name_ar' => 'مدينة الكويت',
        ]);

        return [$country, $state, $city];
    }

    private function makeCompanyBranch(): array
    {
        $company = Company::query()->create([
            'name_en' => 'Geo Company',
            'name_ar' => 'شركة جيو',
            'app_code' => 'GEO-COM-'.uniqid(),
        ]);

        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'name'       => 'Geo Branch',
            'app_code'   => 'GEO-BR-'.uniqid(),
        ]);

        return [$company, $branch];
    }

    private function makeDriver(Company $company, Branch $branch, string $email): Driver
    {
        return Driver::query()->create([
            'company_id' => $company->id,
            'branch_id'  => $branch->id,
            'name'       => 'Geo Driver',
            'email'      => $email,
            'password'   => 'secret',
            'status'     => 'active',
        ]);
    }
}
