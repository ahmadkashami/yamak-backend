<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Dashboard\VehicleController as DashboardVehicleController;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request, DashboardVehicleController $dashboardVehicles): \Illuminate\Http\JsonResponse
    {
        $latitude = $request->query('latitude');
        $longitude = $request->query('longitude');
        $sort = $request->query('sort') === 'desc' ? 'desc' : 'asc';
        $vehicles = Vehicle::query()
            ->with(['branch', 'company', 'services', 'drivers', 'currentDriver'])
            ->where('work_status', 'online')
            ->whereNotNull('current_driver_id')
            ->when($request->query('query'), fn ($query, $search) => $query->where('app_code', 'like', "%{$search}%"))
            ->get()
            ->map(function (Vehicle $vehicle) use ($latitude, $longitude, $dashboardVehicles) {
                $resource = $dashboardVehicles->vehicleResource($vehicle);

                if ($latitude && $longitude && $vehicle->latitude && $vehicle->longitude) {
                    $resource['distance'] = sqrt(
                        pow(((float) $vehicle->latitude) - ((float) $latitude), 2)
                        + pow(((float) $vehicle->longitude) - ((float) $longitude), 2)
                    );
                }

                return $resource;
            })
            ->sortBy('distance', SORT_REGULAR, $sort === 'desc')
            ->values();

        $limit = (int) $request->query('limit', 10);
        $page = (int) $request->query('page', 1);
        $pageItems = $vehicles->forPage($page, $limit)->values();

        return response()->success([
            'list' => $pageItems,
            'metaData' => [
                'totalCount' => $vehicles->count(),
                'currentCount' => $pageItems->count(),
                'currentPage' => $page,
                'itemsPerPage' => $limit,
                'lastPage' => max((int) ceil($vehicles->count() / $limit), 1),
            ],
        ]);
    }

    public function show(Vehicle $vehicle, DashboardVehicleController $dashboardVehicles): \Illuminate\Http\JsonResponse
    {
        return response()->success($dashboardVehicles->vehicleResource(
            $vehicle->load(['branch', 'company', 'services', 'drivers', 'currentDriver'])
        ));
    }
}
