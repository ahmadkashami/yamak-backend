<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Api\Dashboard\VehicleController as DashboardVehicleController;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function owner(Request $request, DashboardVehicleController $dashboardVehicles): \Illuminate\Http\JsonResponse
    {
        $vehicle = $this->vehicleForDriver($request->user());

        if (! $vehicle) {
            return response()->error('no vehicle found', 404);
        }

        return response()->success($dashboardVehicles->vehicleResource($vehicle));
    }

    public function updateLocation(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();

        $data = $request->validate([
            'location.latitude' => ['nullable', 'numeric'],
            'location.longitude' => ['nullable', 'numeric'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $vehicle = Vehicle::query()
            ->where('current_driver_id', $driver->id)
            ->whereHas('drivers', fn ($query) => $query->where('drivers.id', $driver->id))
            ->first();

        if (! $vehicle) {
            return response()->error('no vehicle found', 404);
        }

        if ($vehicle->work_status === 'offline') {
            return response()->error('vehicle should be online', 400);
        }

        $location = $data['location'] ?? $data;
        $vehicle->update([
            'latitude' => $location['latitude'] ?? $vehicle->latitude,
            'longitude' => $location['longitude'] ?? $vehicle->longitude,
            'last_active_date' => now(),
        ]);

        return response()->success($vehicle->fresh());
    }

    public function update(Request $request, Vehicle $vehicle, DashboardVehicleController $dashboardVehicles): \Illuminate\Http\JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();

        if ((int) $vehicle->current_driver_id !== (int) $driver->id) {
            return response()->error('vehicle not found ', 404);
        }

        $data = $request->validate([
            'workStatus' => ['required', 'string', 'in:online,offline'],
        ]);

        $vehicle->update([
            'work_status' => $data['workStatus'],
            'last_active_date' => now(),
        ]);

        return response()->success($dashboardVehicles->vehicleResource($vehicle->fresh(['branch', 'company', 'services', 'drivers', 'currentDriver'])));
    }

    public function link(Request $request, DashboardVehicleController $dashboardVehicles): \Illuminate\Http\JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();
        $vehicle = $this->vehicleForDriver($driver);

        if (! $vehicle) {
            return response()->error('no vehicle found', 404);
        }

        if ($vehicle->current_driver_id && (int) $vehicle->current_driver_id !== (int) $driver->id) {
            return response()->error('vehicle with another ', 400);
        }

        if ((int) $vehicle->current_driver_id === (int) $driver->id) {
            $vehicle->update([
                'current_driver_id' => null,
                'work_status' => 'offline',
            ]);
        } else {
            $vehicle->update([
                'current_driver_id' => $driver->id,
                'work_status' => 'online',
                'last_active_date' => now(),
            ]);
        }

        return response()->success($dashboardVehicles->vehicleResource($vehicle->fresh(['branch', 'company', 'services', 'drivers', 'currentDriver'])));
    }

    public function unlink(Request $request, DashboardVehicleController $dashboardVehicles): \Illuminate\Http\JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();
        $vehicle = $this->vehicleForDriver($driver);

        if (! $vehicle) {
            return response()->error('no vehicle found', 404);
        }

        if ((int) $vehicle->current_driver_id !== (int) $driver->id) {
            return response()->error('vehicle not found ', 404);
        }

        $vehicle->update([
            'current_driver_id' => null,
            'work_status' => 'offline',
        ]);

        return response()->success($dashboardVehicles->vehicleResource($vehicle->fresh(['branch', 'company', 'services', 'drivers', 'currentDriver'])));
    }

    private function vehicleForDriver(Driver $driver): ?Vehicle
    {
        return Vehicle::query()
            ->with(['branch', 'company', 'services', 'drivers', 'currentDriver'])
            ->whereHas('drivers', fn ($query) => $query->where('drivers.id', $driver->id))
            ->first();
    }
}
