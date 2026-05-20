<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Dashboard\AppUserController;
use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\UserOtp;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;

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

    public function signup(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
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

        UserOtp::query()->updateOrCreate(
            ['email' => $email, 'phone' => null],
            [
                'code' => $this->newCode(),
                'code_expire_date' => now()->addDays(2),
            ]
        );

        return response()->success([
            'user' => $appUsers->appUserResource($user),
            'accessToken' => $user->createToken('mobile_token', ['mobile'])->plainTextToken,
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
            UserOtp::query()->updateOrCreate(
                ['email' => $email, 'phone' => null],
                [
                    'code' => $this->newCode(),
                    'code_expire_date' => now()->addDays(2),
                ]
            );
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
        $socialUser = $this->socialUser($request);

        if (! $socialUser) {
            return response()->error('invalid credentials', 400);
        }

        $existingUser = AppUser::query()
            ->whereRaw('lower(email) = ?', [strtolower($socialUser['email'])])
            ->where('is_email_verified', true)
            ->first();

        if ($existingUser) {
            return response()->error('already registered', 400);
        }

        $user = AppUser::query()->create([
            'email' => strtolower($socialUser['email']),
            'name' => $socialUser['name'],
            'is_email_verified' => true,
            'is_social_signup' => true,
            'status' => 'active',
        ]);

        return response()->success([
            'user' => $appUsers->appUserResource($user),
            'accessToken' => $user->createToken('mobile_token', ['mobile'])->plainTextToken,
        ]);
    }

    public function loginSocial(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
    {
        $socialUser = $this->socialUser($request);

        if (! $socialUser) {
            return response()->error('invalid credentials', 400);
        }

        $user = AppUser::query()
            ->whereRaw('lower(email) = ?', [strtolower($socialUser['email'])])
            ->where('is_email_verified', true)
            ->first();

        if (! $user) {
            return response()->error('user not registered', 400);
        }

        $user->forceFill(['last_login' => now()])->save();

        return response()->success([
            'user' => $appUsers->appUserResource($user->fresh()),
            'accessToken' => $user->createToken('mobile_token', ['mobile'])->plainTextToken,
        ]);
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

    public function sendResetPassword(Request $request): \Illuminate\Http\JsonResponse
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

    private function socialUser(Request $request): ?array
    {
        $data = $request->validate([
            'googleAccessToken' => ['nullable', 'string'],
            'facebookAccessToken' => ['nullable', 'string'],
            'appleAccessToken' => ['nullable', 'string'],
            'appleInfo' => ['nullable', 'array'],
        ]);

        try {
            if (! empty($data['googleAccessToken'])) {
                return $this->googleUser($data['googleAccessToken']);
            }

            if (! empty($data['facebookAccessToken'])) {
                return $this->facebookUser($data['facebookAccessToken']);
            }

            if (! empty($data['appleAccessToken'])) {
                return $this->appleUser($data['appleAccessToken'], $data['appleInfo'] ?? []);
            }
        } catch (RequestException) {
            return null;
        }

        return null;
    }

    private function googleUser(string $token): ?array
    {
        $payload = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $token,
        ])->throw()->json();

        $clientId = config('services.google.client_id');

        if ($clientId && ($payload['aud'] ?? null) !== $clientId) {
            return null;
        }

        if (empty($payload['email'])) {
            return null;
        }

        return [
            'email' => $payload['email'],
            'name' => $payload['name'] ?? $payload['email'],
        ];
    }

    private function facebookUser(string $token): ?array
    {
        $payload = Http::get('https://graph.facebook.com/me', [
            'access_token' => $token,
            'fields' => 'id,name,email',
        ])->throw()->json();

        if (empty($payload['email'])) {
            return null;
        }

        return [
            'email' => $payload['email'],
            'name' => $payload['name'] ?? $payload['email'],
        ];
    }

    private function appleUser(string $token, array $appleInfo): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) < 2) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        if (empty($payload['email'])) {
            return null;
        }

        $fullName = $appleInfo['fullName'] ?? [];
        $name = trim(($fullName['givenName'] ?? '').' '.($fullName['familyName'] ?? ''));

        return [
            'email' => $payload['email'],
            'name' => $name ?: $payload['email'],
        ];
    }
}
