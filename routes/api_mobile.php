<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Mobile\AdController;
use App\Http\Controllers\Api\Mobile\AppSettingController;
use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\RequestController;
use App\Http\Controllers\Api\Mobile\ServiceController;
use App\Http\Controllers\Api\Mobile\UserCarController;
use App\Http\Controllers\Api\Mobile\UserController;
use App\Http\Controllers\Api\Mobile\UserNotificationController;
use App\Http\Controllers\Api\Mobile\VehicleController;

Route::prefix('mobile/client/v1')->name('api.mobile_legacy.')->group(function () {
    Route::post('register', [AuthController::class, 'legacyRegister'])->name('register');
});

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
    Route::post('auth/signup-social', [AuthController::class, 'signupSocial'])->name('auth.signup_social');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('auth/login-social', [AuthController::class, 'loginSocial'])->name('auth.login_social');
    Route::post('auth/verify-email', [AuthController::class, 'verifyEmail'])->name('auth.verify_email');
    Route::post('auth/send-reset-password', [AuthController::class, 'sendResetPassword'])->name('auth.send_reset_password');
    Route::post('auth/check-reset-password', [AuthController::class, 'checkResetPassword'])->name('auth.check_reset_password');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset_password');
    Route::get('services/{service}/vehicles', [ServiceController::class, 'vehicles'])->name('services.vehicles');
    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
    Route::get('vehicles/{vehicle}', [VehicleController::class, 'show'])->name('vehicles.show');
    Route::apiResource('requests', RequestController::class)->except(['create', 'edit']);

    Route::middleware(['auth:sanctum', 'api.area:mobile'])->group(function () {
        Route::get('app-users/profile', [UserController::class, 'profile'])->name('app-users.profile');
        Route::patch('app-users/profile', [UserController::class, 'updateProfile'])->name('app-users.profile.update');
        Route::delete('app-users/account', [UserController::class, 'deleteAccount'])->name('app-users.account.delete');
        Route::get('user-cars', [UserCarController::class, 'index'])->name('user-cars.index');
        Route::post('user-cars', [UserCarController::class, 'store'])->name('user-cars.store');
        Route::delete('user-cars/{userCar}', [UserCarController::class, 'destroy'])->name('user-cars.destroy');
        Route::get('user-notifications/mine', [UserNotificationController::class, 'mine'])->name('user-notifications.mine');
    });
});
