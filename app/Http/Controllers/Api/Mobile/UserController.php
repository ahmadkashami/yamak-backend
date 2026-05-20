<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Dashboard\AppUserController;
use App\Http\Controllers\Controller;
use App\Models\AppUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function profile(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        if (! $user) {
            return response()->error('user not found', 404);
        }

        return response()->success($appUsers->appUserResource($user));
    }

    public function updateProfile(Request $request, AppUserController $appUsers): \Illuminate\Http\JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        if (! $user) {
            return response()->error('user not found', 404);
        }

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255', Rule::unique('app_users', 'email')->ignore($user->id)],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'string'],
            'gender' => ['nullable', 'string', 'max:50'],
            'birthDay' => ['nullable', 'date'],
            'language' => ['nullable', 'string', 'max:10'],
            'fcmToken' => ['nullable', 'string'],
            'notificationToken' => ['nullable', 'string'],
            'notificationsEnabled' => ['nullable', 'boolean'],
            'location.latitude' => ['nullable', 'numeric'],
            'location.longitude' => ['nullable', 'numeric'],
        ]);

        $user->update($this->payload($data));

        return response()->success($appUsers->appUserResource($user->fresh()));
    }

    public function deleteAccount(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        if (! $user) {
            return response()->error('user not found', 400);
        }

        AppUser::query()
            ->where('id', $user->id)
            ->orWhere(function ($query) use ($user) {
                $query->when($user->email, fn ($query) => $query->orWhere('email', $user->email))
                    ->when($user->phone, fn ($query) => $query->orWhere('phone', $user->phone));
            })
            ->delete();

        $user->currentAccessToken()?->delete();

        return response()->success(['isDeleted' => true]);
    }

    private function payload(array $data): array
    {
        $payload = [];

        foreach ([
            'email' => 'email',
            'name' => 'name',
            'phone' => 'phone',
            'photo' => 'photo',
            'gender' => 'gender',
            'language' => 'language',
            'fcmToken' => 'fcm_token',
            'notificationToken' => 'notification_token',
            'notificationsEnabled' => 'notifications_enabled',
            'birthDay' => 'birth_day',
        ] as $requestKey => $column) {
            if (array_key_exists($requestKey, $data)) {
                $payload[$column] = $data[$requestKey];
            }
        }

        if (isset($data['location'])) {
            $payload['latitude'] = $data['location']['latitude'] ?? null;
            $payload['longitude'] = $data['location']['longitude'] ?? null;
        }

        return $payload;
    }
}
