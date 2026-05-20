<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\Request;
use RuntimeException;

class NotificationController extends Controller
{
    public function __construct(private readonly FirebaseNotificationService $firebase)
    {
    }

    public function sendToUser(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'userId' => ['required', 'integer', 'exists:app_users,id'],
            'notification.title' => ['required', 'string', 'max:255'],
            'notification.body' => ['required', 'string'],
            'notification.data' => ['nullable', 'array'],
        ]);

        $user = AppUser::query()->find($data['userId']);

        if (! $user) {
            return response()->error('user not found', 400);
        }

        if (! $user->fcm_token) {
            return response()->error('no fcmToken found in this user', 400);
        }

        try {
            return response()->success($this->firebase->sendToToken($user->fcm_token, $data['notification']));
        } catch (RuntimeException $exception) {
            return response()->error($exception->getMessage(), 500);
        }
    }

    public function sendToTopic(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:255'],
            'message.title' => ['required', 'string', 'max:255'],
            'message.body' => ['required', 'string'],
            'message.data' => ['nullable', 'array'],
        ]);

        try {
            return response()->success($this->firebase->sendToTopic($data['topic'], $data['message']));
        } catch (RuntimeException $exception) {
            return response()->error($exception->getMessage(), 500);
        }
    }
}
