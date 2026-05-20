<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Mobile\AdController;
use App\Http\Controllers\Api\Mobile\AppSettingController;
use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\UserController;

Route::prefix('mobile/v1')->name('api.mobile.')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'serverDate' => now()->format('n/j/Y'),
            'statusCode' => 200,
            'status' => 'success',
            'path' => '/' . request()->path() . '/',
            'duration' => '0ms',
            'method' => request()->method(),
            'data' => [
                'url' => '/' . request()->path() . '/',
                'status' => 'success',
                'message' => 'mobile server is running successfully',
                'date' => now()->toJSON(),
            ],
        ]);
    })->name('root');

    Route::get('adds', [AdController::class, 'index'])->name('adds.index');
    Route::get('adds/{ad}', [AdController::class, 'show'])->name('adds.show');
    Route::get('app-setting', [AppSettingController::class, 'index'])->name('app-setting.index');
    Route::post('auth/sendCode', [AuthController::class, 'sendCode'])->name('auth.send_code');
    Route::post('auth/checkCode', [AuthController::class, 'checkCode'])->name('auth.check_code');
    Route::post('auth/signup', [AuthController::class, 'signup'])->name('auth.signup');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('auth/verify-email', [AuthController::class, 'verifyEmail'])->name('auth.verify_email');
    Route::post('auth/send-reset-password', [AuthController::class, 'sendResetPassword'])->name('auth.send_reset_password');
    Route::post('auth/check-reset-password', [AuthController::class, 'checkResetPassword'])->name('auth.check_reset_password');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset_password');

    Route::middleware(['auth:sanctum', 'api.area:mobile'])->group(function () {
        Route::get('app-users/profile', [UserController::class, 'profile'])->name('app-users.profile');
        Route::patch('app-users/profile', [UserController::class, 'updateProfile'])->name('app-users.profile.update');
        Route::delete('app-users/account', [UserController::class, 'deleteAccount'])->name('app-users.account.delete');
    });
});
