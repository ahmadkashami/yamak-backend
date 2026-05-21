<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FirebaseNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class DashboardParityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

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
