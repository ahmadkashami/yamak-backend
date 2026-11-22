<?php

namespace App\Http\Controllers\Api\Mobile\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\MyRequestDetailResource;
use App\Http\Resources\Mobile\MyRequestSummaryResource;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API v2 — ServiceRequests (authenticated, owner-scoped).
 *
 * B2.2B.1 scope: authentication + authorization foundation ONLY.
 *
 * Identity is always taken from the Sanctum-authenticated AppUser; it is never
 * read from client-supplied payloads. B3.7 replaces the former Dashboard
 * serializer on the two GET actions with the customer-safe Mobile allowlist
 * resources. The v2 outer envelope, flat collection shape, detail shape,
 * owner scoping and fail-closed POST behaviour are unchanged.
 */
class RequestController extends Controller
{
    /**
     * Owner-scoped listing. A mobile user may only ever see their own
     * ServiceRequests, serialized with the approved mobile summary allowlist.
     */
    public function index(Request $request): JsonResponse
    {
        $serviceRequests = ServiceRequest::query()
            ->where('app_user_id', $request->user()->id)
            ->with(['vehicle', 'services'])
            ->latest('id')
            ->get()
            ->map(fn (ServiceRequest $serviceRequest) => (new MyRequestSummaryResource($serviceRequest))->resolve($request))
            ->values()
            ->all();

        return response()->success($serviceRequests);
    }

    /**
     * Owner-only detail. A request owned by another user resolves to 404 so its
     * existence is never leaked. Only the approved mobile detail allowlist is
     * returned; Dashboard/admin fields are never serialized here.
     */
    public function show(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        if ((int) $serviceRequest->app_user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $serviceRequest->load(['vehicle', 'driver', 'services', 'statusHistories']);

        return response()->success((new MyRequestDetailResource($serviceRequest))->resolve($request));
    }

    public function store(Request $request): JsonResponse
    {
        abort(404);
    }
}
