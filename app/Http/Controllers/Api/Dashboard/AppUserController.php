<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use Illuminate\Http\Request;

class AppUserController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $users = AppUser::query()
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $users->getCollection()->map(fn (AppUser $user) => $this->appUserResource($user))->values(),
            'metaData' => $this->paginationMeta($users),
        ]);
    }

    public function show(AppUser $appUser): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->appUserResource($appUser));
    }

    public function appUserResource(AppUser $user): array
    {
        return [
            'id' => $user->id,
            '_id' => (string) $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'username' => $user->name,
            'email' => $user->email,
            'lastLogin' => optional($user->last_login)->toJSON(),
            'birthDay' => optional($user->birth_day)->toDateString(),
            'gender' => $user->gender,
            'status' => $user->status,
            'fcmToken' => $user->fcm_token,
            'notificationsEnabled' => $user->notifications_enabled,
            'isEmailVerified' => $user->is_email_verified,
            'createdAt' => optional($user->created_at)->toJSON(),
        ];
    }

    private function paginationMeta($paginator): array
    {
        return [
            'totalCount' => $paginator->total(),
            'currentCount' => $paginator->count(),
            'currentPage' => $paginator->currentPage(),
            'itemsPerPage' => $paginator->perPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }
}
