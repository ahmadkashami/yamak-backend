<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ExpoNotificationService;
use App\Services\FirebaseNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class DashboardParityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_dashboard_auth_stats_companies_branches_services_and_websites(): void
    {
        $this->postJson('/api/dashboard/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user', 'accessToken']]);

        $this->actingAsDashboardAdmin();

        $this->getJson('/api/dashboard/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [
                'websiteCount',
                'driverCount',
                'companyCount',
                'serviceCount',
                'appUserCount',
                'vehicleCount',
                'branchCount',
            ]]);

        $companyId = $this->postJson('/api/dashboard/v1/companies', [
            'name' => ['en' => 'Parity Company', 'ar' => 'Parity Company'],
            'email' => 'parity-company@example.com',
            'phone' => '500000100',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name.en', 'Parity Company')
            ->json('data.id');

        $serviceId = $this->postJson('/api/dashboard/v1/services', [
            'name' => ['en' => 'Parity Tow', 'ar' => 'Parity Tow'],
            'logo' => 'https://example.com/tow.png',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name.en', 'Parity Tow')
            ->json('data.id');

        $branchId = $this->postJson('/api/dashboard/v1/branches', [
            'companyId' => $companyId,
            'name' => 'Parity Branch',
            'services' => [$serviceId],
            'geoLocation' => [
                'latitude' => 29.3759,
                'longitude' => 47.9774,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.companyId', (string) $companyId)
            ->assertJsonPath('data.services.0', (string) $serviceId)
            ->json('data.id');

        $this->getJson('/api/dashboard/v1/companies?query=Parity')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.list.0.id', $companyId);

        $this->getJson('/api/dashboard/v1/branches/'.$branchId.'/services')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $serviceId);

        $websiteId = $this->postJson('/api/dashboard/v1/websites', [
            'companyId' => $companyId,
            'domainUrl' => 'parity.example.test',
            'aboutUs' => ['en' => 'About', 'ar' => 'About'],
            'contactUs' => [
                'phone' => '500000200',
                'email' => 'web@example.com',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.companyId', (string) $companyId)
            ->json('data.id');

        $this->getJson('/api/dashboard/v1/websites/domains?domainUrl=parity.example.test')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $websiteId)
            ->assertJsonPath('data.services.0.id', $serviceId);
    }

    public function test_dashboard_app_users_requests_notifications_and_upload_error_shape(): void
    {
        $this->actingAsDashboardAdmin();
        $firebase = Mockery::mock(FirebaseNotificationService::class);
        $firebase->shouldReceive('sendToToken')
            ->once()
            ->andReturn(['name' => 'projects/test/messages/1']);
        $firebase->shouldReceive('sendToTopic')
            ->once()
            ->andReturn(['name' => 'projects/test/messages/2']);
        $this->instance(FirebaseNotificationService::class, $firebase);
        $expo = Mockery::mock(ExpoNotificationService::class);
        $expo->shouldNotReceive('sendToToken');
        $this->instance(ExpoNotificationService::class, $expo);

        [$service, $driver, $user] = $this->createOperationalFixture();

        $this->getJson('/api/dashboard/v1/app-users?query=app.user')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.list.0.email', 'app.user@example.com');

        $requestId = $this->postJson('/api/dashboard/v1/requests', [
            'driverId' => $driver->id,
            'userId' => $user->id,
            'services' => [$service->id],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.driver.id', $driver->id)
            ->json('data.id');

        $this->getJson('/api/dashboard/v1/requests?query=Parity')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.list.0.id', $requestId);

        $user->update(['fcm_token' => 'test-fcm-token']);
        $this->postJson('/api/dashboard/v1/notifications/users', [
            'userId' => $user->id,
            'notification' => [
                'title' => 'Hello',
                'body' => 'Body',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'projects/test/messages/1');

        $this->postJson('/api/dashboard/v1/notifications/topics', [
            'topic' => 'all',
            'message' => [
                'title' => 'Hello',
                'body' => 'Body',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'projects/test/messages/2');

        $this->postJson('/api/dashboard/v1/files-upload')
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'size limit : 1mb , file type : jpg|jpeg|png ');
    }

    public function test_dashboard_notifications_can_fall_back_to_expo_token(): void
    {
        $this->actingAsDashboardAdmin();

        $firebase = Mockery::mock(FirebaseNotificationService::class);
        $firebase->shouldNotReceive('sendToToken');
        $firebase->shouldNotReceive('sendToTopic');
        $this->instance(FirebaseNotificationService::class, $firebase);

        $expo = Mockery::mock(ExpoNotificationService::class);
        $expo->shouldReceive('sendToToken')
            ->once()
            ->with('ExpoPushToken[test-token]', [
                'title' => 'Expo',
                'body' => 'Body',
            ])
            ->andReturn(['data' => ['status' => 'ok', 'id' => 'expo-ticket-1']]);
        $this->instance(ExpoNotificationService::class, $expo);

        $user = AppUser::query()->where('email', 'app.user@example.com')->firstOrFail();
        $user->update([
            'fcm_token' => null,
            'notification_token' => 'ExpoPushToken[test-token]',
        ]);

        $this->postJson('/api/dashboard/v1/notifications/users', [
            'userId' => $user->id,
            'notification' => [
                'title' => 'Expo',
                'body' => 'Body',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.status', 'ok')
            ->assertJsonPath('data.data.id', 'expo-ticket-1');
    }

    public function test_dashboard_sys_users_roles_and_locations(): void
    {
        $this->actingAsDashboardAdmin();

        $roleId = $this->postJson('/api/dashboard/v1/roles', [
            'name' => 'PARITY_MANAGER',
            'display_name' => 'Parity Manager',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'PARITY_MANAGER')
            ->json('data.id');

        $this->getJson('/api/dashboard/v1/roles?search=Parity')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.0.name', 'PARITY_MANAGER');

        $this->putJson('/api/dashboard/v1/roles/'.$roleId, [
            'name' => 'PARITY_EDITOR',
            'display_name' => 'Parity Editor',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'PARITY_EDITOR');

        $userId = $this->postJson('/api/dashboard/v1/sys-users', [
            'fullName' => 'Parity Dashboard User',
            'email' => 'parity-dashboard-user@example.com',
            'password' => '123456',
            'mobile' => '500009901',
            'role_id' => $roleId,
            'type' => 'dashboard',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Parity Dashboard User')
            ->assertJsonPath('data.role', 'PARITY_EDITOR')
            ->json('data.id');

        $this->getJson('/api/dashboard/v1/sys-users?search=Parity Dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.list.0.email', 'parity-dashboard-user@example.com');

        $this->putJson('/api/dashboard/v1/sys-users/'.$userId, [
            'name' => 'Parity Dashboard User Updated',
            'email' => 'parity-dashboard-user@example.com',
            'mobile' => '500009902',
            'role_id' => $roleId,
            'type' => 'dashboard',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Parity Dashboard User Updated');

        $countryId = $this->postJson('/api/dashboard/v1/countries', [
            'name' => ['en' => 'Parity Country', 'ar' => 'Parity Country'],
            'countryCode' => '990',
            'currency' => 'PCD',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name.en', 'Parity Country')
            ->json('data.id');

        $stateId = $this->postJson('/api/dashboard/v1/states/bulk', [
            [
                'stateEN' => 'Parity State',
                'stateAR' => 'Parity State',
                'countryID' => ['$oid' => $countryId],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.count', 1)
            ->json('data.list.0.id');

        $cityId = $this->postJson('/api/dashboard/v1/cities/bulk', [
            [
                'cityEN' => 'Parity City',
                'cityAR' => 'Parity City',
                'stateID' => ['$oid' => $stateId],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.count', 1)
            ->json('data.list.0.id');

        $this->getJson('/api/dashboard/v1/countries/'.$countryId.'/states')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.list.0.id', $stateId);

        $this->getJson('/api/dashboard/v1/states/'.$stateId.'/cities')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.list.0.id', $cityId);

        $this->getJson('/api/dashboard/v1/cities?query=Parity City')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.list.0.id', $cityId);
    }

    public function test_dashboard_company_branch_related_lists_and_website_content(): void
    {
        $this->actingAsDashboardAdmin();

        $companyId = $this->postJson('/api/dashboard/v1/companies', [
            'name' => ['en' => 'Parity Related Company', 'ar' => 'Parity Related Company'],
            'email' => 'parity-related@example.com',
            'phone' => '500007001',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.id');

        $serviceId = $this->postJson('/api/dashboard/v1/services', [
            'name' => ['en' => 'Parity Related Service', 'ar' => 'Parity Related Service'],
            'logo' => 'https://example.com/related-service.png',
        ])
            ->assertOk()
            ->json('data.id');

        $branchId = $this->postJson('/api/dashboard/v1/branches', [
            'companyId' => $companyId,
            'name' => 'Parity Related Branch',
            'services' => [$serviceId],
        ])
            ->assertOk()
            ->json('data.id');

        $driverId = Driver::query()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'name' => 'Related Driver',
            'email' => 'related-driver@example.com',
            'password' => '123456',
            'status' => 'active',
        ])->id;

        $vehicleId = Vehicle::query()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'app_code' => 'VEH-RELATED',
            'status' => 'active',
            'work_status' => 'available',
        ])->id;

        $this->getJson('/api/dashboard/v1/companies/'.$companyId.'/branches')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.list.0.id', $branchId);

        $this->getJson('/api/dashboard/v1/companies/'.$companyId.'/drivers')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.list.0.id', $driverId);

        $this->getJson('/api/dashboard/v1/companies/'.$companyId.'/vehicles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.list.0.id', $vehicleId);

        $this->getJson('/api/dashboard/v1/branches/'.$branchId.'/free-drivers')
            ->assertOk()
            ->assertJsonPath('success', true);

        $websiteId = $this->postJson('/api/dashboard/v1/websites', [
            'companyId' => $companyId,
            'domainUrl' => 'related.example.test',
            'aboutUs' => ['en' => 'Related About', 'ar' => 'Related About'],
            'contactUs' => ['phone' => '500007002', 'email' => 'related-web@example.com'],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.id');

        $featureId = $this->postJson('/api/dashboard/v1/website-features', [
            'companyId' => $companyId,
            'websiteId' => $websiteId,
            'icon' => 'car',
            'title' => ['en' => 'Feature', 'ar' => 'Feature'],
            'description' => ['en' => 'Description', 'ar' => 'Description'],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.id');

        $sliderId = $this->postJson('/api/dashboard/v1/website-sliders', [
            'companyId' => $companyId,
            'websiteId' => $websiteId,
            'imageUrl' => 'https://example.com/slider.png',
            'title' => ['en' => 'Slider', 'ar' => 'Slider'],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.id');

        $carouselId = $this->postJson('/api/dashboard/v1/website-carousels', [
            'companyId' => $companyId,
            'websiteId' => $websiteId,
            'imageUrl' => 'https://example.com/carousel.png',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.id');

        $this->getJson('/api/dashboard/v1/website-themes/websites/'.$websiteId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.websiteId', (string) $websiteId);

        $this->getJson('/api/dashboard/v1/websites/domains?domainUrl=related.example.test')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $websiteId)
            ->assertJsonPath('data.services.0.id', $serviceId)
            ->assertJsonPath('data.features.0.id', $featureId)
            ->assertJsonPath('data.sliders.0.id', $sliderId)
            ->assertJsonPath('data.carousel.0.id', $carouselId)
            ->assertJsonPath('data.theme.websiteId', (string) $websiteId);
    }

    private function actingAsDashboardAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin, ['dashboard']);

        return $admin;
    }

    private function createOperationalFixture(): array
    {
        $company = Company::query()->create([
            'name_en' => 'Parity Ops Company',
            'name_ar' => 'Parity Ops Company',
            'email' => 'parity-ops@example.com',
            'app_code' => 'COM-PARITY-OPS',
        ]);
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'name' => 'Parity Ops Branch',
            'app_code' => 'BRA-PARITY-OPS',
        ]);
        $service = Service::query()->create([
            'name_en' => 'Parity Ops Service',
            'name_ar' => 'Parity Ops Service',
            'app_code' => 'SER-PARITY-OPS',
            'status' => 'active',
        ]);
        $driver = Driver::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Parity Driver',
            'email' => 'parity-driver@example.com',
            'password' => '123456',
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'current_driver_id' => $driver->id,
            'app_code' => 'VEH-PARITY-OPS',
            'status' => 'active',
            'work_status' => 'online',
        ]);
        $vehicle->services()->attach($service->id);
        $vehicle->drivers()->attach($driver->id);
        $driver->update(['vehicle_id' => $vehicle->id]);

        return [$service, $driver, AppUser::query()->where('email', 'app.user@example.com')->firstOrFail()];
    }
}
