<?php

use Illuminate\Support\Facades\Route;

Route::prefix('dashboard/v1')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'area' => 'dashboard',
            'status' => 'ok',
        ]);
    });
});
