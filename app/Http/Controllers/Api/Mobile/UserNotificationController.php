<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class UserNotificationController extends Controller
{
    public function mine(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        return response()->success(
            UserNotification::query()
                ->where(function ($query) use ($user) {
                    $query->where('type', 'all')
                        ->orWhere('app_user_id', $user->id);
                })
                ->latest('id')
                ->get()
                ->map(fn (UserNotification $notification) => $this->notificationResource($notification))
                ->values()
        );
    }

    private function notificationResource(UserNotification $notification): array
    {
        return [
            'id' => $notification->id,
            '_id' => (string) $notification->id,
            'title' => $notification->title,
            'body' => $notification->body,
            'userId' => $notification->app_user_id ? (string) $notification->app_user_id : null,
            'description' => $notification->description,
            'type' => $notification->type,
            'isRead' => $notification->is_read,
            'expiredAt' => optional($notification->expired_at)->toJSON(),
            'createdAt' => optional($notification->created_at)->toJSON(),
            'updatedAt' => optional($notification->updated_at)->toJSON(),
        ];
    }
}
