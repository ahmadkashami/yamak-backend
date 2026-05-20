<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;

class RequestController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $requests = ServiceRequest::query()
            ->with(['appUser', 'driver.branch', 'driver.company', 'services'])
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('status', 'like', "%{$search}%")
                        ->orWhere('platform', 'like', "%{$search}%")
                        ->orWhereHas('appUser', fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('driver', fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('phone_num', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
                });
            })
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $requests->getCollection()->map(fn (ServiceRequest $serviceRequest) => $this->requestResource($serviceRequest))->values(),
            'metaData' => $this->paginationMeta($requests),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'platform' => ['nullable', 'string', 'max:50'],
            'services' => ['nullable', 'array'],
            'services.*' => ['integer', 'exists:services,id'],
            'userId' => ['required', 'integer', 'exists:app_users,id'],
            'driverId' => ['required', 'integer', 'exists:drivers,id'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $driver = Driver::query()->findOrFail($data['driverId']);
        $serviceRequest = ServiceRequest::query()->create([
            'platform' => $data['platform'] ?? 'mobile',
            'app_user_id' => $data['userId'],
            'driver_id' => $driver->id,
            'company_id' => $driver->company_id,
            'branch_id' => $driver->branch_id,
            'status' => $data['status'] ?? 'active',
        ]);

        $serviceRequest->services()->sync($data['services'] ?? []);

        return response()->success($this->requestResource($serviceRequest->fresh(['appUser', 'driver.branch', 'driver.company', 'services'])), 'Request created successfully');
    }

    public function requestResource(ServiceRequest $serviceRequest): array
    {
        return [
            'id' => $serviceRequest->id,
            '_id' => (string) $serviceRequest->id,
            'platform' => $serviceRequest->platform,
            'services' => $serviceRequest->services->map(fn ($service) => app(ServiceController::class)->serviceResource($service))->values(),
            'userId' => $serviceRequest->app_user_id ? (string) $serviceRequest->app_user_id : null,
            'user' => $serviceRequest->appUser ? app(AppUserController::class)->appUserResource($serviceRequest->appUser) : null,
            'driverId' => $serviceRequest->driver_id ? (string) $serviceRequest->driver_id : null,
            'driver' => $serviceRequest->driver ? app(DriverController::class)->driverResource($serviceRequest->driver) : null,
            'status' => $serviceRequest->status,
            'createdAt' => optional($serviceRequest->created_at)->toJSON(),
        ];
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
