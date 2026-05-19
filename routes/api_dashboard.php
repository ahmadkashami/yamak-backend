<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Dashboard\AuthController;
use App\Http\Controllers\Api\Dashboard\RoleController;
use App\Http\Controllers\Api\Dashboard\UserController;

Route::prefix('dashboard/v1')->name('api.dashboard.')->group(function () {
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
                'message' => 'server is running successfully',
                'date' => now()->toJSON(),
            ],
        ]);
    })->name('root');

    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::middleware(['auth:sanctum', 'api.area:dashboard'])->group(function () {
        Route::get('sys-users/profile', [AuthController::class, 'profile'])->name('sys_users.profile');
        Route::put('sys-users/profile', [AuthController::class, 'updateProfile'])->name('sys_users.profile.update');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::apiResource('sys-users', UserController::class)
            ->parameters(['sys-users' => 'user'])
            ->except(['destroy']);
        Route::apiResource('roles', RoleController::class)->except(['destroy']);
    });
});
