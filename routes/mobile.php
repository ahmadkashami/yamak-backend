<?php

use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'area' => 'mobile',
            'status' => 'ok',
        ]);
    });
});
