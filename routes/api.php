<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;



include "dashboard.php";
include "mobile.php";
include "driver.php";

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});
