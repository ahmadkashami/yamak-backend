<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Dashboard\ServiceController as DashboardServiceController;
use App\Http\Controllers\Api\Dashboard\VehicleController as DashboardVehicleController;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request, DashboardServiceController $dashboardServices): \Illuminate\Http\JsonResponse
    {
        $services = Service::query()
            ->where('status', 'active')
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name_en', 'like', "%{$search}%")
                        ->orWhere('name_ar', 'like', "%{$search}%")
                        ->orWhere('app_code', 'like', "%{$search}%");
                });
            })
            ->oldest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $services->getCollection()->map(fn (Service $service) => $dashboardServices->serviceResource($service))->values(),
            'metaData' => $this->paginationMeta($services),
        ]);
    }

    public function vehicles(Service $service, Request $request, DashboardVehicleController $dashboardVehicles): \Illuminate\Http\JsonResponse
    {
        $latitude = $request->query('latitude');
        $longitude = $request->query('longitude');
        $vehicles = Vehicle::query()
            ->with(['branch', 'company', 'services', 'drivers', 'currentDriver'])
            ->where('work_status', 'online')
            ->whereNotNull('current_driver_id')
            ->whereHas('services', fn ($query) => $query->where('services.id', $service->id))
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
            ->sortBy('distance')
            ->values();

        return response()->success($vehicles);
    }

    private function paginationMeta($paginator): array
    {
        return [
            'totalCount' => $paginator->total(),
            'currentCount' => $paginator->count(),
            'currentPage' => $paginator->currentPage(),
            'itemsPerPage' => $paginator->perPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }
}
