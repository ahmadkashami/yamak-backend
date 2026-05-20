<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Mobile\AdController;
use App\Http\Controllers\Api\Mobile\AppSettingController;

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
});
