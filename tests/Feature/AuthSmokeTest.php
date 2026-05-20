<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_dashboard_login_token_can_access_profile(): void
    {
        $token = $this->postJson('/api/dashboard/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user', 'accessToken', 'token_type']])
            ->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/v1/sys-users/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'email']]);
    }

    public function test_mobile_login_token_can_access_profile(): void
    {
        $token = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'app.user@example.com',
            'password' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['isVerified', 'user', 'accessToken']])
            ->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/mobile/v1/app-users/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'email']]);
    }

    public function test_driver_login_token_can_access_profile(): void
    {
        $company = Company::query()->create([
            'name_en' => 'Demo Company',
            'name_ar' => 'Demo Company',
            'email' => 'demo-company@example.com',
            'app_code' => 'COM-DEMO',
        ]);

        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'name' => 'Demo Branch',
            'app_code' => 'BR-DEMO',
        ]);

        Driver::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Demo Driver',
            'email' => 'driver@example.com',
            'password' => '123456',
            'status' => 'active',
        ]);

        $token = $this->postJson('/api/driver/v1/auth/login', [
            'email' => 'driver@example.com',
            'password' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['driver', 'accessToken']])
            ->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/driver/v1/drivers/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'email']]);
    }
}
