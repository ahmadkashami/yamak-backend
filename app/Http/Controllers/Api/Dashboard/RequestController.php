<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\RequestReason;
use App\Models\ServiceRequest;
use App\Services\RequestLifecyclePolicy;
use App\Services\RequestReasonPolicy;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RequestController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $requests = ServiceRequest::query()
            ->with(['appUser', 'driver.branch', 'driver.company', 'vehicle', 'services'])
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('status', 'like', "%{$search}%")
                        ->orWhere('platform', 'like', "%{$search}%")
                        ->orWhereHas('appUser', fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('driver', fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('phone_num', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
                });
            })
            ->when($request->query('status'), function ($query) {
                $query->where('status', $request->query('status'));
            })
            ->when($request->query('channel'), function ($query) {
                $query->where('channel', $request->query('channel'));
            })
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $requests->getCollection()->map(fn (ServiceRequest $serviceRequest) => $this->requestResource($serviceRequest))->values(),
            'metaData' => $this->paginationMeta($requests),
        ]);
    }

    public function show(Request $request, $requestId): \Illuminate\Http\JsonResponse
    {
        $serviceRequest = ServiceRequest::with(['appUser', 'driver.branch', 'driver.company', 'vehicle', 'services', 'statusHistories.reasons'])
            ->find($requestId);

        if (!$serviceRequest) {
            return response()->error('not_found', 404, null, 'NOT_FOUND');
        }

        $statusHistories = $serviceRequest->statusHistories()
            ->with(['reasons'])
            ->latest('created_at')
            ->get();

        return response()->success([
            'data' => $this->requestResource($serviceRequest),
            // Destinations the lifecycle policy allows from the current status,
            // so the UI never has to duplicate the transition matrix.
            'allowedTransitions' => collect(['contact_initiated', 'pending', 'accepted', 'completed', 'not_completed', 'cancelled'])
                ->filter(fn (string $toStatus) => RequestLifecyclePolicy::isAllowed($serviceRequest->status, $toStatus))
                ->values(),
            // Server-authoritative reason catalog (codes + Arabic labels).
            'availableReasons' => RequestReason::query()->orderBy('id')->get(['code', 'label_ar'])
                ->map(fn (RequestReason $reason) => ['code' => $reason->code, 'labelAr' => $reason->label_ar])
                ->values(),
            'statusHistory' => $statusHistories->map(fn ($history) => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'actor_type' => $history->actor_type,
                'actor_id' => $history->actor_id,
                'reason_note' => $history->reason_note,
                'reasons' => $history->reasons->map(fn ($reason) => $reason->code),
                'created_at' => $history->created_at->toJSON(),
            ])->values(),
        ]);
    }

    public function statusUpdate(Request $request, $requestId): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'toStatus' => ['required', 'string'],
            'expectedStatus' => ['sometimes', 'nullable', 'string'],
            'reasonCodes' => ['sometimes', 'array'],
            'reasonNote' => ['sometimes', 'nullable', 'string'],
        ]);

        $toStatus = $data['toStatus'];
        $expectedStatus = $data['expectedStatus'] ?? null;
        $reasonCodes = $data['reasonCodes'] ?? [];
        $reasonNote = $data['reasonNote'] ?? null;

        // Catalog is authoritative: resolve codes to ids once, then validate the
        // selection against those trusted codes.
        $reasonIdsByCode = RequestReason::query()->pluck('id', 'code');

        // Reason violations are request errors (422), not server errors.
        try {
            RequestReasonPolicy::assertValid($toStatus, $reasonCodes, $reasonNote, $reasonIdsByCode->keys()->all());
        } catch (InvalidArgumentException $e) {
            return response()->error($e->getMessage(), 422, ['reasonCodes' => [$e->getMessage()]], 'VALIDATION_ERROR');
        }

        // Execute atomic transaction: lock request, validate, update, record history + reasons
        $history = null;
        $persistedCodes = [];
        try {
            \DB::transaction(function () use ($requestId, $toStatus, $expectedStatus, $reasonCodes, $reasonNote, $reasonIdsByCode, &$history, &$persistedCodes) {
                // Lock the request row for update
                $serviceRequest = ServiceRequest::where('id', $requestId)->lockForUpdate()->firstOrFail();

                // Re-check current status after lock
                $currentStatus = $serviceRequest->status;

                // If expectedStatus is provided, validate it matches current status
                if ($expectedStatus !== null && $currentStatus !== $expectedStatus) {
                    throw new InvalidArgumentException(sprintf(
                        'Stale request state: current status [%s], expected [%s]',
                        $currentStatus,
                        $expectedStatus
                    ));
                }

                // Validate the transition with the actual (locked) from_status
                RequestLifecyclePolicy::assertAllowed($currentStatus, $toStatus);

                // Update the request status (do not modify channel, vehicle/driver, coordinates, etc.)
                $serviceRequest->status = $toStatus;
                $serviceRequest->save();

                // Record the status history event
                $history = $serviceRequest->statusHistories()->create([
                    'from_status' => $currentStatus,
                    'to_status' => $toStatus,
                    'actor_type' => 'admin',
                    'actor_id' => auth()->id(),
                    'reason_note' => $reasonNote,
                ]);

                // Attach all approved reasons to the history entry (pivot table)
                if ($reasonCodes !== []) {
                    $reasonIds = collect($reasonCodes)
                        ->map(fn (string $code) => (int) $reasonIdsByCode->get($code))
                        ->unique()
                        ->values()
                        ->all();

                    $history->reasons()->sync($reasonIds);
                    $persistedCodes = $reasonCodes;
                }
            });
        } catch (InvalidArgumentException $e) {
            // Stale expectedStatus and disallowed/terminal/same-status
            // transitions are conflicts with current state (409).
            return response()->error($e->getMessage(), 409, null, 'INVALID_TRANSITION');
        }

        if (!$history) {
            return response()->error('transition_failed', 500, null, 'TRANSITION_FAILED');
        }

        return response()->success([
            'data' => $this->requestResource(ServiceRequest::with(['appUser', 'driver.branch', 'driver.company', 'vehicle', 'services'])->find($requestId)),
            'statusHistory' => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'actor_type' => $history->actor_type,
                'actor_id' => $history->actor_id,
                'reason_note' => $history->reason_note,
                'reasons' => $persistedCodes,
                'created_at' => $history->created_at->toJSON(),
            ],
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
            'channel' => $serviceRequest->channel,
            'services' => $serviceRequest->services->map(fn ($service) => app(ServiceController::class)->serviceResource($service))->values(),
            'userId' => $serviceRequest->app_user_id ? (string) $serviceRequest->app_user_id : null,
            'user' => $serviceRequest->appUser ? app(AppUserController::class)->appUserResource($serviceRequest->appUser) : null,
            'driverId' => $serviceRequest->driver_id ? (string) $serviceRequest->driver_id : null,
            'driver' => $serviceRequest->driver ? app(DriverController::class)->driverResource($serviceRequest->driver) : null,
            'vehicleId' => $serviceRequest->vehicle_id ? (string) $serviceRequest->vehicle_id : null,
            'vehicle' => $serviceRequest->vehicle ? [
                'id' => $serviceRequest->vehicle->id,
                '_id' => (string) $serviceRequest->vehicle->id,
                'labelNumber' => $serviceRequest->vehicle->label_number,
                'modelName' => $serviceRequest->vehicle->model_name,
                'type' => $serviceRequest->vehicle->type,
                'status' => $serviceRequest->vehicle->status,
                'workStatus' => $serviceRequest->vehicle->work_status,
            ] : null,
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
