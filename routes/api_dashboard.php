<?php

use Illuminate\Support\Facades\Route;

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
});
