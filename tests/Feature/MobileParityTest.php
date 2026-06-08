<?php

namespace Tests\Feature;

use App\Mail\UserRegistrationCodeMail;
use App\Mail\UserRequestOrderMail;
use App\Mail\UserResetPasswordCodeMail;
use App\Models\Ad;
use App\Models\AppSetting;
use App\Models\AppUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Service;
use App\Models\UserNotification;
use App\Models\UserOtp;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileParityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_mobile_auth_otp_signup_login_legacy_register_and_reset_flows(): void
    {
        Mail::fake();
        $whatsApp = $this->fakeWhatsApp();

        $sendCode = $this->postJson('/api/mobile/v1/auth/sendCode', [
            'phone' => '965500000003',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['message', 'code']]);

        $this->postJson('/api/mobile/v1/auth/checkCode', [
            'phone' => '965500000003',
            'code' => $sendCode->json('data.code'),
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user', 'accessToken']]);

        $signup = $this->postJson('/api/mobile/v1/auth/signup', [
            'email' => 'new.mobile@example.com',
            'password' => '123456',
            'name' => 'New Mobile User',
            'phone' => '96550535103',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user', 'accessToken']]);

        Mail::assertSent(UserRegistrationCodeMail::class, fn (UserRegistrationCodeMail $mail) => $mail->hasTo('new.mobile@example.com'));
        $whatsApp->assertAuthMessageSentTo('96550535103');

        $code = UserOtp::query()->where('email', 'new.mobile@example.com')->value('code');
        $this->postJson('/api/mobile/v1/auth/verify-email', [
            'email' => 'new.mobile@example.com',
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'new.mobile@example.com');

        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => 'new.mobile@example.com',
            'password' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.isVerified', true)
            ->assertJsonStructure(['data' => ['user', 'accessToken']]);

        $this->postJson('/api/mobile/client/v1/register', [
            'email' => 'legacy.mobile@example.com',
            'password' => '123456',
            'name' => 'Legacy Mobile User',
            'phone' => '965500000005',
        ])
            ->assertOk()
            ->assertJsonStructure(['data', 'access_token']);

        $this->postJson('/api/mobile/v1/auth/send-reset-password', [
            'email' => $signup->json('data.user.email'),
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(UserResetPasswordCodeMail::class, fn (UserResetPasswordCodeMail $mail) => $mail->hasTo('new.mobile@example.com'));
        $resetCode = AppUser::query()->where('email', 'new.mobile@example.com')->value('reset_pass_code');
        $whatsApp->assertMessageContaining('96550535103', $resetCode);

        $this->postJson('/api/mobile/v1/auth/check-reset-password', [
            'email' => 'new.mobile@example.com',
            'code' => $resetCode,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.isChecked', true);

        $this->postJson('/api/mobile/v1/auth/reset-password', [
            'email' => 'new.mobile@example.com',
            'password' => '654321',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user', 'accessToken']]);
    }

    public function test_mobile_social_auth_routes_are_not_registered_for_current_phase(): void
    {
        $this->postJson('/api/mobile/v1/auth/signup-social', [
            'googleAccessToken' => 'fake-google-token',
        ])->assertNotFound();

        $this->postJson('/api/mobile/v1/auth/login-social', [
            'googleAccessToken' => 'fake-google-token',
        ])->assertNotFound();
    }

    public function test_mobile_profile_user_cars_and_notifications(): void
    {
        $user = AppUser::query()->where('email', 'app.user@example.com')->firstOrFail();
        UserNotification::query()->create([
            'app_user_id' => $user->id,
            'title' => 'User Notice',
            'body' => 'Body',
            'type' => 'user',
        ]);
        UserNotification::query()->create([
            'title' => 'Global Notice',
            'body' => 'Body',
            'type' => 'all',
        ]);
        Sanctum::actingAs($user, ['mobile']);

        $this->getJson('/api/mobile/v1/app-users/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'app.user@example.com');

        $this->patchJson('/api/mobile/v1/app-users/profile', [
            'name' => 'Updated Mobile User',
            'language' => 'ar',
            'notificationToken' => 'expo-token-1',
            'notificationsEnabled' => true,
            'location' => [
                'latitude' => 29.3759,
                'longitude' => 47.9774,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Updated Mobile User')
            ->assertJsonPath('data.language', 'ar')
            ->assertJsonPath('data.notificationToken', 'expo-token-1')
            ->assertJsonPath('data.notificationsEnabled', true);

        $carId = $this->postJson('/api/mobile/v1/user-cars', [
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => '2024',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.make', 'Toyota')
            ->json('data.id');

        $this->getJson('/api/mobile/v1/user-cars')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/mobile/v1/user-notifications/mine')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.title', 'User Notice')
            ->assertJsonPath('data.1.title', 'Global Notice')
            ->assertJsonCount(2, 'data');

        $this->deleteJson('/api/mobile/v1/user-cars/'.$carId)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_mobile_lookup_ads_settings_services_vehicles_requests_and_empty_pagination(): void
    {
        $this->getJson('/api/mobile/v1/vehicles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.metaData.totalCount', 0)
            ->assertJsonPath('data.metaData.lastPage', 1)
            ->assertJsonCount(0, 'data.list');

        $setting = AppSetting::query()->create([
            'android_version_code' => '1',
            'android_recommended_version' => '1',
            'ios_version' => '1.0.0',
            'ios_recommended_version' => '1.0.0',
        ]);
        $ad = Ad::query()->create([
            'title' => 'Mobile Ad',
            'photo' => 'https://example.com/ad.png',
            'started_at' => now()->subDay(),
            'expired_at' => now()->addDay(),
            'status' => 'active',
        ]);
        [$service, $vehicle, $driver, $user] = $this->createMobileLookupFixture();
        Mail::fake();

        $this->getJson('/api/mobile/v1/app-setting')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $setting->id);

        $this->getJson('/api/mobile/v1/adds')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.metaData.totalCount', 1)
            ->assertJsonPath('data.list.0.id', $ad->id);

        $this->getJson('/api/mobile/v1/services?limit=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.metaData.totalCount', 1)
            ->assertJsonPath('data.list.0.id', $service->id);

        $this->getJson('/api/mobile/v1/vehicles?latitude=29.1&longitude=47.1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.metaData.totalCount', 1)
            ->assertJsonPath('data.list.0.id', $vehicle->id)
            ->assertJsonPath('data.list.0.geoLocation.coordinates.0', 47.9774)
            ->assertJsonPath('data.list.0.geoLocation.coordinates.1', 29.3759);

        $this->getJson('/api/mobile/v1/services/'.$service->id.'/vehicles?latitude=29.1&longitude=47.1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $vehicle->id)
            ->assertJsonPath('data.0.geoLocation.coordinates.0', 47.9774)
            ->assertJsonPath('data.0.geoLocation.coordinates.1', 29.3759);

        $requestId = $this->postJson('/api/mobile/v1/requests', [
            'driverId' => $driver->id,
            'userId' => $user->id,
            'services' => [$service->id],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.driver.id', $driver->id)
            ->json('data.id');

        Mail::assertSent(UserRequestOrderMail::class, fn (UserRequestOrderMail $mail) => $mail->hasTo($driver->email));

        $this->getJson('/api/mobile/v1/requests/'.$requestId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $requestId);
    }

    private function createMobileLookupFixture(): array
    {
        $company = Company::query()->create([
            'name_en' => 'Mobile Company',
            'name_ar' => 'Mobile Company',
            'email' => 'mobile-company@example.com',
        ]);
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'name' => 'Mobile Branch',
        ]);
        $service = Service::query()->create([
            'name_en' => 'Tow',
            'name_ar' => 'Tow',
            'status' => 'active',
        ]);
        $driver = Driver::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Mobile Driver',
            'email' => 'mobile.driver@example.com',
            'password' => '123456',
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'current_driver_id' => $driver->id,
            'status' => 'active',
            'work_status' => 'online',
            'latitude' => 29.3759,
            'longitude' => 47.9774,
        ]);
        $vehicle->services()->attach($service->id);
        $vehicle->drivers()->attach($driver->id);
        $driver->update(['vehicle_id' => $vehicle->id]);
        $user = AppUser::query()->where('email', 'app.user@example.com')->firstOrFail();

        return [$service, $vehicle, $driver, $user];
    }

}
