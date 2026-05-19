<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__ . '/api_dashboard.php';
require __DIR__ . '/api_mobile.php';
require __DIR__ . '/api_driver.php';

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});
