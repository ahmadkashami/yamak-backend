<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Api\Dashboard\DriverController;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request, DriverController $driverController): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $driver = Driver::query()
            ->with(['branch', 'company', 'vehicle'])
            ->where('email', $data['email'])
            ->first();

        if (! $driver) {
            return response()->error('invalid email', 404);
        }

        if (! Hash::check($data['password'], $driver->password)) {
            return response()->error('invalid password', 404);
        }

        $driver->forceFill(['last_login' => now()])->save();

        return response()->success([
            'driver' => $driverController->driverResource($driver->fresh(['branch', 'company', 'vehicle'])),
            'accessToken' => $driver->createToken('driver_token', ['driver'])->plainTextToken,
        ]);
    }
}
