<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Driver\AuthController;
use App\Http\Controllers\Api\Driver\DriverController;
use App\Http\Controllers\Api\Driver\VehicleController;

$driverHealth = function () {
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
            'message' => 'driver server is running successfully',
            'date' => now()->toJSON(),
        ],
    ]);
};

Route::prefix('driver/v1')->name('api.driver.')->group(function () use ($driverHealth) {
    Route::get('/', $driverHealth)->name('root');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::middleware(['auth:sanctum', 'api.area:driver'])->group(function () {
        Route::get('drivers/profile', [DriverController::class, 'profile'])->name('drivers.profile');
        Route::put('drivers/profile', [DriverController::class, 'updateProfile'])->name('drivers.profile.update');
        Route::get('vehicles/owner', [VehicleController::class, 'owner'])->name('vehicles.owner');
        Route::put('vehicles/location', [VehicleController::class, 'updateLocation'])->name('vehicles.location');
        Route::put('vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');
        Route::post('vehicles/link', [VehicleController::class, 'link'])->name('vehicles.link');
    });
});

// Legacy NestJS root kept during migration until all clients use /api/driver/v1.
Route::prefix('driver-app/v1')->name('api.driver_legacy.')->group(function () use ($driverHealth) {
    Route::get('/', $driverHealth)->name('root');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::middleware(['auth:sanctum', 'api.area:driver'])->group(function () {
        Route::get('drivers/profile', [DriverController::class, 'profile'])->name('drivers.profile');
        Route::put('drivers/profile', [DriverController::class, 'updateProfile'])->name('drivers.profile.update');
        Route::get('vehicles/owner', [VehicleController::class, 'owner'])->name('vehicles.owner');
        Route::put('vehicles/location', [VehicleController::class, 'updateLocation'])->name('vehicles.location');
        Route::put('vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');
        Route::post('vehicles/link', [VehicleController::class, 'link'])->name('vehicles.link');
    });
});
