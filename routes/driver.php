<?php

use Illuminate\Support\Facades\Route;

Route::prefix('driver/v1')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'area' => 'driver',
            'status' => 'ok',
        ]);
    });
});
