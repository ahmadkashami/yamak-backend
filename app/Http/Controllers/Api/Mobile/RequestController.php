<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Dashboard\RequestController as DashboardRequestController;
use App\Http\Controllers\Controller;
use App\Services\RequestCreationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class RequestController extends Controller
{
    public function index(DashboardRequestController $dashboardRequests): JsonResponse
    {
        return response()->success(
            ServiceRequest::query()
                ->with(['appUser', 'driver.branch', 'driver.company', 'services'])
                ->latest('id')
                ->get()
                ->map(fn (\App\Models\ServiceRequest $serviceRequest) => $dashboardRequests->requestResource($serviceRequest))
                ->values()
        );
    }

    public function store(Request $request, DashboardRequestController $dashboardRequests, RequestCreationService $service): JsonResponse
    {
        $data = $request->validate([
            'vehicleId' => ['required', 'integer'],
            'driverId' => ['required', 'integer'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['integer'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->input('idempotencyKey');

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            throw ValidationException::withMessages([
                'idempotencyKey' => ['The Idempotency-Key header is required.'],
            ]);
        }

        try {
            $result = $service->create(
                $request->user(),
                (int) $data['vehicleId'],
                (int) $data['driverId'],
                $data['services'],
                $idempotencyKey,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'idempotencyKey' => [$e->getMessage()],
            ]);
        } catch (DomainException $e) {
            throw ValidationException::withMessages([
                'idempotencyKey' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'idempotencyKey' => [$e->getMessage()],
            ]);
        }

        $serviceRequest = $result['request']->fresh(['appUser', 'driver.branch', 'driver.company', 'services']);

        return response()->success($dashboardRequests->requestResource($serviceRequest));
    }

    public function show(ServiceRequest $request, DashboardRequestController $dashboardRequests): \Illuminate\Http\JsonResponse
    {
        return response()->success($dashboardRequests->requestResource(
            $request->load(['appUser', 'driver.branch', 'driver.company', 'services'])
        ));
    }

    public function update(Request $httpRequest, ServiceRequest $request, DashboardRequestController $dashboardRequests): \Illuminate\Http\JsonResponse
    {
        $data = $httpRequest->validate([
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $request->update([
            'status' => $data['status'] ?? $request->status,
        ]);

        return response()->success($dashboardRequests->requestResource(
            $request->fresh(['appUser', 'driver.branch', 'driver.company', 'services'])
        ));
    }

    public function destroy(ServiceRequest $request): \Illuminate\Http\JsonResponse
    {
        $request->delete();

        return response()->success(null);
    }
}
