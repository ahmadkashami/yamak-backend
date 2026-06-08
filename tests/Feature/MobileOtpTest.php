<?php

namespace Tests\Feature;

use App\Mail\UserRegistrationCodeMail;
use App\Mail\UserResetPasswordCodeMail;
use App\Models\AppUser;
use App\Models\UserOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Focused tests for the mobile OTP, WhatsApp notification, and password-reset flows.
 *
 * Uses FakeWhatsAppService (via $this->fakeWhatsApp()) and Mail::fake() — no real
 * credentials or HTTP calls needed.
 */
class MobileOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ─── sendCode / checkCode (phone OTP) ────────────────────────────────────

    public function test_send_code_creates_otp_and_returns_code(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/sendCode', ['phone' => '96500000010'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['message', 'code']]);

        $this->assertNotEmpty($response->json('data.code'));
        $this->assertSame('code sent successfully', $response->json('data.message'));
        $this->assertDatabaseHas('user_otps', ['phone' => '96500000010']);
    }

    public function test_send_code_reuses_unexpired_otp(): void
    {
        $first = $this->postJson('/api/mobile/v1/auth/sendCode', ['phone' => '96500000011'])
            ->assertOk()
            ->json('data.code');

        $second = $this->postJson('/api/mobile/v1/auth/sendCode', ['phone' => '96500000011'])
            ->assertOk()
            ->json('data.code');

        $this->assertSame($first, $second);
        $this->assertSame(1, UserOtp::query()->where('phone', '96500000011')->count());
    }

    public function test_check_code_happy_path_returns_user_and_token(): void
    {
        $code = $this->postJson('/api/mobile/v1/auth/sendCode', ['phone' => '96500000012'])
            ->json('data.code');

        $this->postJson('/api/mobile/v1/auth/checkCode', ['phone' => '96500000012', 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['data' => ['user', 'accessToken']])
            ->assertJsonPath('data.user.phone', '96500000012');
    }

    public function test_check_code_wrong_code_returns_400(): void
    {
        $this->postJson('/api/mobile/v1/auth/sendCode', ['phone' => '96500000013'])->assertOk();

        $this->postJson('/api/mobile/v1/auth/checkCode', ['phone' => '96500000013', 'code' => '0000'])
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_check_code_unknown_phone_returns_400(): void
    {
        $this->postJson('/api/mobile/v1/auth/checkCode', ['phone' => '96599999999', 'code' => '1234'])
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    // ─── signup → OTP email + WhatsApp auth template ─────────────────────────

    public function test_signup_sends_email_otp_and_whatsapp_auth_template(): void
    {
        Mail::fake();
        $whatsApp = $this->fakeWhatsApp();

        $this->postJson('/api/mobile/v1/auth/signup', [
            'email'    => 'otp.test@example.com',
            'password' => 'secret123',
            'name'     => 'OTP Tester',
            'phone'    => '96500000020',
        ])->assertOk()->assertJsonStructure(['data' => ['user', 'accessToken']]);

        Mail::assertSent(UserRegistrationCodeMail::class,
            fn ($m) => $m->hasTo('otp.test@example.com')
        );

        $whatsApp->assertAuthMessageSentTo('96500000020');

        $code = UserOtp::query()->where('email', 'otp.test@example.com')->value('code');
        $whatsApp->assertAuthMessageSentWith('96500000020', $code);
    }

    public function test_signup_duplicate_verified_email_returns_400(): void
    {
        Mail::fake();
        $this->fakeWhatsApp();

        AppUser::query()->create([
            'email'             => 'existing@example.com',
            'password'          => 'secret',
            'name'              => 'Existing',
            'phone'             => '96500000021',
            'is_email_verified' => true,
            'status'            => 'active',
        ]);

        $this->postJson('/api/mobile/v1/auth/signup', [
            'email'    => 'existing@example.com',
            'password' => 'newpass',
            'name'     => 'Duplicate',
            'phone'    => '96500000022',
        ])->assertStatus(400)->assertJsonPath('success', false);
    }

    // ─── verify-email ─────────────────────────────────────────────────────────

    public function test_verify_email_with_correct_code_sets_verified(): void
    {
        Mail::fake();
        $this->fakeWhatsApp();

        $this->postJson('/api/mobile/v1/auth/signup', [
            'email' => 'verify.me@example.com', 'password' => 'pass', 'name' => 'V', 'phone' => '96500000030',
        ])->assertOk();

        $code = UserOtp::query()->where('email', 'verify.me@example.com')->value('code');

        $this->postJson('/api/mobile/v1/auth/verify-email', ['email' => 'verify.me@example.com', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'verify.me@example.com');

        $this->assertTrue(AppUser::query()->where('email', 'verify.me@example.com')->value('is_email_verified'));
    }

    public function test_verify_email_wrong_code_returns_400(): void
    {
        Mail::fake();
        $this->fakeWhatsApp();

        $this->postJson('/api/mobile/v1/auth/signup', [
            'email' => 'wrongcode@example.com', 'password' => 'pass', 'name' => 'W', 'phone' => '96500000031',
        ])->assertOk();

        $this->postJson('/api/mobile/v1/auth/verify-email', ['email' => 'wrongcode@example.com', 'code' => '0000'])
            ->assertStatus(400);
    }

    // ─── password reset flow ──────────────────────────────────────────────────

    public function test_send_reset_password_sends_email_and_whatsapp_message(): void
    {
        Mail::fake();
        $whatsApp = $this->fakeWhatsApp();

        AppUser::query()->create([
            'email'             => 'reset.target@example.com',
            'password'          => 'oldpass',
            'name'              => 'Reset Target',
            'phone'             => '96500000040',
            'is_email_verified' => true,
            'status'            => 'active',
        ]);

        $this->postJson('/api/mobile/v1/auth/send-reset-password', ['email' => 'reset.target@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(UserResetPasswordCodeMail::class,
            fn ($m) => $m->hasTo('reset.target@example.com')
        );

        $code = AppUser::query()->where('email', 'reset.target@example.com')->value('reset_pass_code');
        $whatsApp->assertMessageContaining('96500000040', $code);
    }

    public function test_send_reset_password_unknown_email_returns_400(): void
    {
        $this->fakeWhatsApp();
        $this->postJson('/api/mobile/v1/auth/send-reset-password', ['email' => 'nobody@example.com'])
            ->assertStatus(400);
    }

    public function test_check_reset_password_correct_code_returns_is_checked_true(): void
    {
        Mail::fake();
        $this->fakeWhatsApp();

        $user = AppUser::query()->create([
            'email'             => 'check.reset@example.com',
            'password'          => 'pass',
            'name'              => 'Check Reset',
            'phone'             => '96500000041',
            'is_email_verified' => true,
            'status'            => 'active',
        ]);

        $this->postJson('/api/mobile/v1/auth/send-reset-password', ['email' => 'check.reset@example.com'])
            ->assertOk();

        $code = AppUser::query()->where('email', 'check.reset@example.com')->value('reset_pass_code');

        $this->postJson('/api/mobile/v1/auth/check-reset-password', [
            'email' => 'check.reset@example.com',
            'code'  => $code,
        ])->assertOk()->assertJsonPath('data.isChecked', true);
    }

    public function test_check_reset_password_wrong_code_returns_is_checked_false(): void
    {
        Mail::fake();
        $this->fakeWhatsApp();

        AppUser::query()->create([
            'email'             => 'wrong.reset@example.com',
            'password'          => 'pass',
            'name'              => 'Wrong Reset',
            'phone'             => '96500000042',
            'is_email_verified' => true,
            'status'            => 'active',
        ]);

        $this->postJson('/api/mobile/v1/auth/send-reset-password', ['email' => 'wrong.reset@example.com'])
            ->assertOk();

        $this->postJson('/api/mobile/v1/auth/check-reset-password', [
            'email' => 'wrong.reset@example.com',
            'code'  => '0000',
        ])->assertOk()->assertJsonPath('data.isChecked', false);
    }

    public function test_reset_password_changes_password_and_returns_new_token(): void
    {
        Mail::fake();
        $this->fakeWhatsApp();

        AppUser::query()->create([
            'email'             => 'new.pass@example.com',
            'password'          => 'oldpassword',
            'name'              => 'New Pass',
            'phone'             => '96500000043',
            'is_email_verified' => true,
            'status'            => 'active',
        ]);

        $this->postJson('/api/mobile/v1/auth/reset-password', [
            'email'    => 'new.pass@example.com',
            'password' => 'newpassword123',
        ])->assertOk()->assertJsonStructure(['data' => ['user', 'accessToken']]);

        $this->postJson('/api/mobile/v1/auth/login', [
            'email'    => 'new.pass@example.com',
            'password' => 'newpassword123',
        ])->assertOk()->assertJsonPath('data.isVerified', true);
    }
}
