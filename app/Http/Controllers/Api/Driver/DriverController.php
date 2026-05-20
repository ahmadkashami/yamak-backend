<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Api\Dashboard\DriverController as DashboardDriverController;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    public function profile(Request $request, DashboardDriverController $dashboardDrivers): \Illuminate\Http\JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();

        return response()->success($dashboardDrivers->driverResource($driver->load(['branch', 'company', 'vehicle'])));
    }

    public function updateProfile(Request $request, DashboardDriverController $dashboardDrivers): \Illuminate\Http\JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'photo' => ['nullable', 'string'],
            'licenseExpiredAt' => ['nullable', 'date'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('drivers', 'email')->ignore($driver->id)],
            'phoneNum' => ['nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'string', 'min:6'],
        ]);

        $driver->update($this->payload($data));

        return response()->success($dashboardDrivers->driverResource($driver->fresh(['branch', 'company', 'vehicle'])));
    }

    private function payload(array $data): array
    {
        $payload = [];

        foreach ([
            'name' => 'name',
            'status' => 'status',
            'photo' => 'photo',
            'email' => 'email',
            'phoneNum' => 'phone_num',
            'password' => 'password',
            'licenseExpiredAt' => 'license_expired_at',
        ] as $requestKey => $column) {
            if (array_key_exists($requestKey, $data)) {
                $payload[$column] = $data[$requestKey];
            }
        }

        return $payload;
    }
}
