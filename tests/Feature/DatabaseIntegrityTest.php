<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\AppSetting;
use App\Models\AppUser;
use App\Models\Branch;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Driver;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\State;
use App\Models\UserCar;
use App\Models\UserNotification;
use App\Models\UserOtp;
use App\Models\Vehicle;
use App\Models\Website;
use App\Models\WebsiteCarousel;
use App\Models\WebsiteFeature;
use App\Models\WebsiteSlider;
use App\Models\WebsiteTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // ─── Table existence ────────────────────────────────────────────────────

    public function test_all_business_tables_exist(): void
    {
        $expected = [
            'companies', 'branches', 'branch_service',
            'services',
            'countries', 'states', 'cities',
            'drivers', 'vehicles', 'driver_vehicle', 'vehicle_service',
            'app_users', 'service_requests', 'request_service',
            'user_cars', 'user_otps', 'user_notifications',
            'websites', 'website_service', 'website_themes',
            'website_features', 'website_sliders', 'website_carousels',
            'ads', 'app_settings',
            'users', 'roles', 'permissions',
        ];

        foreach ($expected as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table [{$table}] is missing from the schema.");
        }
    }

    // ─── Column existence for key entities ─────────────────────────────────

    public function test_users_table_has_tenant_and_location_columns(): void
    {
        foreach (['partner_id', 'country_id', 'city_id'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('users', $column),
                "Column [users.{$column}] is missing."
            );
        }
    }

    public function test_vehicles_table_has_geolocation_columns(): void
    {
        foreach (['latitude', 'longitude'] as $col) {
            $this->assertTrue(Schema::hasColumn('vehicles', $col), "Missing vehicles.{$col}");
        }
    }

    public function test_app_users_table_has_geolocation_columns(): void
    {
        foreach (['latitude', 'longitude'] as $col) {
            $this->assertTrue(Schema::hasColumn('app_users', $col), "Missing app_users.{$col}");
        }
    }

    public function test_branches_table_has_geolocation_and_address_columns(): void
    {
        foreach (['latitude', 'longitude', 'country_id', 'state_id', 'city_id', 'block', 'street', 'zip_code'] as $col) {
            $this->assertTrue(Schema::hasColumn('branches', $col), "Missing branches.{$col}");
        }
    }

    // ─── Location hierarchy ──────────────────────────────────────────────────

    public function test_location_hierarchy_country_state_city(): void
    {
        $country = Country::query()->create([
            'name_en' => 'Kuwait', 'name_ar' => 'الكويت',
            'country_code' => 'KW', 'currency' => 'KWD',
        ]);

        $state = State::query()->create([
            'country_id' => $country->id,
            'name_en' => 'Capital', 'name_ar' => 'العاصمة',
        ]);

        $city = City::query()->create([
            'state_id' => $state->id,
            'name_en' => 'Kuwait City', 'name_ar' => 'مدينة الكويت',
        ]);

        // Country → States
        $this->assertCount(1, $country->states);
        $this->assertSame($state->id, $country->states->first()->id);

        // Country → Cities (through states)
        $this->assertCount(1, $country->cities);
        $this->assertSame($city->id, $country->cities->first()->id);

        // State → Country
        $this->assertSame($country->id, $state->country->id);

        // State → Cities
        $this->assertCount(1, $state->cities);

        // City → State
        $this->assertSame($state->id, $city->state->id);
    }

    // ─── Company hierarchy ───────────────────────────────────────────────────

    public function test_company_has_branches_drivers_vehicles_websites(): void
    {
        [$company, $branch, $service] = $this->createCompanyBranchService();

        $driver = Driver::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'name' => 'Driver A', 'email' => 'driver-a@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'status' => 'active', 'work_status' => 'offline',
        ]);

        $website = Website::query()->create([
            'company_id' => $company->id,
            'domain_url' => 'https://company-a.example.com',
        ]);

        $this->assertCount(1, $company->branches);
        $this->assertCount(1, $company->drivers);
        $this->assertCount(1, $company->vehicles);
        $this->assertCount(1, $company->websites);
        $this->assertSame($branch->id, $company->branches->first()->id);
        $this->assertSame($driver->id, $company->drivers->first()->id);
        $this->assertSame($vehicle->id, $company->vehicles->first()->id);
        $this->assertSame($website->id, $company->websites->first()->id);
    }

    // ─── Branch ↔ Service pivot ──────────────────────────────────────────────

    public function test_branch_services_pivot(): void
    {
        [$company, $branch, $service] = $this->createCompanyBranchService();
        $branch->services()->attach($service->id);

        $this->assertCount(1, $branch->services);
        $this->assertSame($service->id, $branch->services->first()->id);

        // Inverse: service → branches
        $this->assertCount(1, $service->branches);
        $this->assertSame($branch->id, $service->branches->first()->id);
    }

    // ─── Vehicle ↔ Service pivot ─────────────────────────────────────────────

    public function test_vehicle_services_pivot(): void
    {
        [$company, $branch, $service] = $this->createCompanyBranchService();

        $driver = Driver::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'name' => 'Driver B', 'email' => 'driver-b@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'status' => 'active', 'work_status' => 'offline',
        ]);

        $vehicle->services()->attach($service->id);

        $this->assertCount(1, $vehicle->services);
        $this->assertSame($service->id, $vehicle->services->first()->id);

        // Inverse: service → vehicles
        $this->assertCount(1, $service->vehicles);
        $this->assertSame($vehicle->id, $service->vehicles->first()->id);
    }

    // ─── Vehicle ↔ Driver pivot ──────────────────────────────────────────────

    public function test_vehicle_drivers_pivot(): void
    {
        [$company, $branch, $service] = $this->createCompanyBranchService();

        $driver = Driver::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'name' => 'Driver C', 'email' => 'driver-c@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'current_driver_id' => $driver->id,
            'status' => 'active', 'work_status' => 'offline',
        ]);

        $vehicle->drivers()->attach($driver->id);

        $this->assertCount(1, $vehicle->drivers);
        $this->assertSame($driver->id, $vehicle->drivers->first()->id);

        // Inverse: driver → vehicles (many-to-many history)
        $this->assertCount(1, $driver->vehicles);

        // Direct FK: vehicle currentDriver
        $this->assertSame($driver->id, $vehicle->currentDriver->id);
    }

    // ─── Website ↔ Service pivot ─────────────────────────────────────────────

    public function test_website_services_pivot(): void
    {
        [$company, $branch, $service] = $this->createCompanyBranchService();

        $website = Website::query()->create([
            'company_id' => $company->id,
            'domain_url' => 'https://ws.example.com',
        ]);

        $website->services()->attach($service->id);

        $this->assertCount(1, $website->services);
        $this->assertSame($service->id, $website->services->first()->id);

        // Inverse: service → websites
        $this->assertCount(1, $service->websites);
        $this->assertSame($website->id, $service->websites->first()->id);
    }

    // ─── Website child tables ────────────────────────────────────────────────

    public function test_website_has_child_content_tables(): void
    {
        [$company, $branch, $service] = $this->createCompanyBranchService();

        $website = Website::query()->create([
            'company_id' => $company->id,
            'domain_url' => 'https://content.example.com',
        ]);

        $theme = WebsiteTheme::query()->create([
            'company_id' => $company->id, 'website_id' => $website->id,
            'primary_color' => '#000', 'secondary_color' => '#fff',
        ]);

        $feature = WebsiteFeature::query()->create([
            'company_id' => $company->id, 'website_id' => $website->id,
            'icon' => 'star', 'title_en' => 'Feature 1', 'title_ar' => 'ميزة 1',
        ]);

        $slider = WebsiteSlider::query()->create([
            'company_id' => $company->id, 'website_id' => $website->id,
            'image_url' => 'https://example.com/slide.jpg',
        ]);

        $carousel = WebsiteCarousel::query()->create([
            'company_id' => $company->id, 'website_id' => $website->id,
            'image_url' => 'https://example.com/car.jpg',
        ]);

        // Website → children
        $this->assertSame($theme->id, $website->theme->id);
        $this->assertCount(1, $website->features);
        $this->assertCount(1, $website->sliders);
        $this->assertCount(1, $website->carousels);

        // Children → website
        $this->assertSame($website->id, $theme->website->id);
        $this->assertSame($website->id, $feature->website->id);
        $this->assertSame($website->id, $slider->website->id);
        $this->assertSame($website->id, $carousel->website->id);

        // Children → company
        $this->assertSame($company->id, $feature->company->id);
        $this->assertSame($company->id, $slider->company->id);
        $this->assertSame($company->id, $carousel->company->id);
    }

    // ─── ServiceRequest ↔ Service pivot ─────────────────────────────────────

    public function test_service_request_services_pivot(): void
    {
        [$company, $branch, $service] = $this->createCompanyBranchService();

        $appUser = AppUser::query()->create([
            'email' => 'req.user@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $driver = Driver::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'name' => 'Driver D', 'email' => 'driver-d@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $request = ServiceRequest::query()->create([
            'app_user_id' => $appUser->id,
            'driver_id'   => $driver->id,
            'company_id'  => $company->id,
            'branch_id'   => $branch->id,
            'status'      => 'active',
        ]);

        $request->services()->attach($service->id);

        $this->assertCount(1, $request->services);
        $this->assertSame($service->id, $request->services->first()->id);

        // Inverse: service → requests
        $this->assertCount(1, $service->requests);
        $this->assertSame($request->id, $service->requests->first()->id);
    }

    // ─── AppUser child tables ────────────────────────────────────────────────

    public function test_app_user_has_cars_notifications_and_requests(): void
    {
        [$company, $branch, $service] = $this->createCompanyBranchService();

        $appUser = AppUser::query()->create([
            'email' => 'child.user@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $car = UserCar::query()->create([
            'app_user_id' => $appUser->id,
            'make' => 'Toyota', 'model' => 'Camry', 'year' => '2022', 'type' => 'Car',
        ]);

        $notification = UserNotification::query()->create([
            'app_user_id' => $appUser->id,
            'title' => 'Test', 'body' => 'Hello', 'type' => 'general',
        ]);

        $driver = Driver::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'name' => 'Driver E', 'email' => 'driver-e@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $serviceRequest = ServiceRequest::query()->create([
            'app_user_id' => $appUser->id,
            'driver_id'   => $driver->id,
            'status'      => 'active',
        ]);

        // AppUser → cars
        $this->assertCount(1, $appUser->cars);
        $this->assertSame($car->id, $appUser->cars->first()->id);

        // AppUser → notifications
        $this->assertCount(1, $appUser->notifications);
        $this->assertSame($notification->id, $appUser->notifications->first()->id);

        // AppUser → requests
        $this->assertCount(1, $appUser->requests);
        $this->assertSame($serviceRequest->id, $appUser->requests->first()->id);

        // Inverse: car → appUser
        $this->assertSame($appUser->id, $car->appUser->id);
    }

    // ─── Geolocation coordinate order ────────────────────────────────────────

    public function test_vehicle_geolocation_stores_longitude_and_latitude_separately(): void
    {
        [$company, $branch, $service] = $this->createCompanyBranchService();

        $lon = 47.9774;
        $lat = 29.3759;

        $vehicle = Vehicle::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'status' => 'active', 'work_status' => 'offline',
            'latitude' => $lat, 'longitude' => $lon,
        ]);

        $fresh = Vehicle::query()->find($vehicle->id);

        $this->assertEqualsWithDelta($lat, (float) $fresh->latitude, 0.0001);
        $this->assertEqualsWithDelta($lon, (float) $fresh->longitude, 0.0001);
    }

    public function test_app_user_geolocation_stores_longitude_and_latitude_separately(): void
    {
        $lon = 47.9774;
        $lat = 29.3759;

        $appUser = AppUser::query()->create([
            'email' => 'geo.user@example.com',
            'password' => 'secret', 'status' => 'active',
            'latitude' => $lat, 'longitude' => $lon,
        ]);

        $fresh = AppUser::query()->find($appUser->id);

        $this->assertEqualsWithDelta($lat, (float) $fresh->latitude, 0.0001);
        $this->assertEqualsWithDelta($lon, (float) $fresh->longitude, 0.0001);
    }

    // ─── Standalone entities ─────────────────────────────────────────────────

    public function test_ad_can_be_created_with_required_columns(): void
    {
        $ad = Ad::query()->create([
            'title'      => 'Test Ad',
            'photo'      => 'https://example.com/ad.jpg',
            'started_at' => now(),
            'expired_at' => now()->addDays(30),
            'status'     => 'active',
        ]);

        $this->assertNotNull($ad->id);
        $this->assertSame('Test Ad', $ad->title);
    }

    public function test_app_setting_can_be_created(): void
    {
        $setting = AppSetting::query()->create([
            'android_version_code'          => '10',
            'android_recommended_version'   => '10',
            'ios_version'                   => '2.0',
            'ios_recommended_version'       => '2.0',
        ]);

        $this->assertNotNull($setting->id);
    }

    public function test_user_otp_can_be_created_with_phone_or_email(): void
    {
        $byPhone = UserOtp::query()->create([
            'phone' => '+96500000099',
            'code'  => '123456',
            'code_expire_date' => now()->addMinutes(10),
        ]);

        $byEmail = UserOtp::query()->create([
            'email' => 'otp.test@example.com',
            'code'  => '654321',
            'code_expire_date' => now()->addMinutes(10),
        ]);

        $this->assertNotNull($byPhone->id);
        $this->assertNotNull($byEmail->id);
    }

    // ─── Driver ↔ Company / Branch ────────────────────────────────────────────

    public function test_driver_belongs_to_company_and_branch(): void
    {
        [$company, $branch, $service] = $this->createCompanyBranchService();

        $driver = Driver::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'name' => 'Driver F', 'email' => 'driver-f@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $this->assertSame($company->id, $driver->company->id);
        $this->assertSame($branch->id, $driver->branch->id);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createCompanyBranchService(): array
    {
        $company = Company::query()->create([
            'name_en' => 'Integrity Co', 'name_ar' => 'شركة النزاهة',
        ]);

        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'name' => 'Main Branch',
        ]);

        $service = Service::query()->create([
            'name_en' => 'Tow Service', 'name_ar' => 'خدمة الجر',
        ]);

        return [$company, $branch, $service];
    }
}
