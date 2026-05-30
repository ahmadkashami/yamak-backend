<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Dashboard\AppUserController;
use App\Http\Controllers\Controller;
use App\Mail\UserRegistrationCodeMail;
use App\Mail\UserResetPasswordCodeMail;
use App\Models\AppUser;
use App\Models\UserOtp;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function sendCode(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:6'],
        ]);

        $otp = UserOtp::query()
            ->where('phone', $data['phone'])
            ->where('code_expire_date', '>', now())
            ->first();

        if (! $otp) {
            $otp = UserOtp::query()->updateOrCreate(
                ['phone' => $data['phone'], 'email' => null],
                [
                    'code' => $this->newCode(),
                    'code_expire_date' => now()->addDays(2),
                ]
            );
        }

        return response()->success([
            'message' => 'code sent successfully',
            'code' => $otp->code,
        ]);
    }

    public function checkCode(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:6'],
            'code' => ['required', 'string'],
        ]);

        $otp = UserOtp::query()->where('phone', $data['phone'])->latest('id')->first();

        if (! $otp) {
            return response()->error('no registered phone number', 400);
        }

        if ($otp->code !== $data['code']) {
            return response()->error('invalid code', 400);
        }

        $user = AppUser::query()->firstOrCreate(
            ['phone' => $data['phone']],
            [
                'name' => $data['phone'],
                'status' => 'active',
                'is_email_verified' => false,
            ]
        );

        return response()->success([
            'user' => $appUsers->appUserResource($user),
            'accessToken' => $user->createToken('mobile_token', ['mobile'])->plainTextToken,
        ]);
    }

    public function signup(Request $request, AppUserController $appUsers, WhatsAppService $whatsApp): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'min:7', 'max:255'],
        ]);

        $email = strtolower($data['email']);
        $existingUser = AppUser::query()->where('email', $email)->first();

        if ($existingUser?->is_email_verified) {
            return response()->error('email already exist', 400);
        }

        $user = AppUser::query()->updateOrCreate(
            ['email' => $email],
            [
                'email' => $email,
                'password' => $data['password'],
                'name' => $data['name'],
                'phone' => $data['phone'],
                'is_social_signup' => false,
                'status' => 'active',
            ]
        );

        $otp = UserOtp::query()->updateOrCreate(
            ['email' => $email, 'phone' => null],
            [
                'code' => $this->newCode(),
                'code_expire_date' => now()->addDays(2),
            ]
        );

        Mail::to($email)->send(new UserRegistrationCodeMail($user->name, $otp->code));
        $whatsApp->sendAuthMessage($user->phone, $otp->code);

        return response()->success([
            'user' => $appUsers->appUserResource($user),
            'accessToken' => $user->createToken('mobile_token', ['mobile'])->plainTextToken,
        ]);
    }

    public function legacyRegister(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'min:7', 'max:255'],
        ]);

        $email = strtolower($data['email']);
        $user = AppUser::query()->updateOrCreate(
            ['email' => $email],
            [
                'email' => $email,
                'password' => $data['password'],
                'name' => $data['name'],
                'phone' => $data['phone'],
                'is_social_signup' => false,
                'status' => 'active',
            ]
        );

        $token = $user->createToken('mobile_token', ['mobile'])->plainTextToken;

        return response()->json([
            'data' => $appUsers->appUserResource($user),
            'access_token' => $token,
        ]);
    }

    public function login(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower($data['email']);
        $user = AppUser::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where('is_social_signup', false)
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->error('invalid credentials', 400);
        }

        if (! $user->is_email_verified) {
            $otp = UserOtp::query()->updateOrCreate(
                ['email' => $email, 'phone' => null],
                [
                    'code' => $this->newCode(),
                    'code_expire_date' => now()->addDays(2),
                ]
            );

            Mail::to($email)->send(new UserRegistrationCodeMail($user->name, $otp->code));
        }

        $user->forceFill(['last_login' => now()])->save();

        return response()->success([
            'isVerified' => $user->is_email_verified,
            'user' => $appUsers->appUserResource($user->fresh()),
            'accessToken' => $user->createToken('mobile_token', ['mobile'])->plainTextToken,
        ]);
    }

    public function signupSocial(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
    {
        return response()->error('social login is disabled for the current phase', 404);
    }

    public function loginSocial(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
    {
        return response()->error('social login is disabled for the current phase', 404);
    }

    public function verifyEmail(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $email = strtolower($data['email']);
        $otp = UserOtp::query()->whereRaw('lower(email) = ?', [$email])->latest('id')->first();

        if (! $otp) {
            return response()->error('invalid email address', 400);
        }

        if ($otp->code !== $data['code']) {
            return response()->error('invalid code', 400);
        }

        if ($otp->code_expire_date && $otp->code_expire_date->isPast()) {
            return response()->error('expired code', 400);
        }

        $user = AppUser::query()->whereRaw('lower(email) = ?', [$email])->first();

        if (! $user) {
            return response()->error('invalid email address', 400);
        }

        $user->update(['is_email_verified' => true]);

        return response()->success([
            'user' => $appUsers->appUserResource($user->fresh()),
            'accessToken' => $user->createToken('mobile_token', ['mobile'])->plainTextToken,
        ]);
    }

    public function sendResetPassword(Request $request, WhatsAppService $whatsApp): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower($data['email']);
        $user = AppUser::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->where('is_email_verified', true)
            ->first();

        if (! $user) {
            return response()->error('email not registered', 400);
        }

        $user->update([
            'reset_pass_code' => $this->newCode(),
            'reset_pass_expire_date' => now()->addDays(2),
        ]);

        Mail::to($email)->send(new UserResetPasswordCodeMail($user->name, $user->reset_pass_code));
        $whatsApp->sendMessage(
            $user->phone,
            "*{$user->reset_pass_code}* is your verification code. For your security, do not share this code  "
        );

        return response()->success('successful mail sent');
    }

    public function checkResetPassword(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $user = AppUser::query()->whereRaw('lower(email) = ?', [strtolower($data['email'])])->first();

        if (! $user) {
            return response()->error('email not registered', 400);
        }

        if ($user->reset_pass_expire_date && $user->reset_pass_expire_date->isPast()) {
            return response()->error('code expired', 400);
        }

        return response()->success([
            'isChecked' => $user->reset_pass_code === $data['code'],
        ]);
    }

    public function resetPassword(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = AppUser::query()->whereRaw('lower(email) = ?', [strtolower($data['email'])])->first();

        if (! $user) {
            return response()->error('email not registered', 400);
        }

        $user->update(['password' => $data['password']]);

        return response()->success([
            'user' => $appUsers->appUserResource($user->fresh()),
            'accessToken' => $user->createToken('mobile_token', ['mobile'])->plainTextToken,
        ]);
    }

    private function newCode(): string
    {
        return (string) random_int(1000, 9999);
    }

}
