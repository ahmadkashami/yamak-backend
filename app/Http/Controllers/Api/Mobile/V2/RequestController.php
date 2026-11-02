<?php

namespace App\Http\Controllers\Api\Mobile\V2;

use App\Http\Controllers\Api\Dashboard\RequestController as DashboardRequestController;
use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API v2 — ServiceRequests (authenticated, owner-scoped).
 *
 * B2.2B.1 scope: authentication + authorization foundation ONLY.
 *
 * Identity is always taken from the Sanctum-authenticated AppUser; it is never
 * read from client-supplied payloads. This is a READ-ONLY surface for now:
 * request creation stays fail-closed until eligibility (B2.2B.2) and
 * idempotency (B2.2B.3) hardening land. It must not become an operational
 * write path before then.
 */
class RequestController extends Controller
{
    /**
     * Owner-scoped listing. A mobile user may only ever see their own
     * ServiceRequests.
     */
    public function index(Request $request, DashboardRequestController $dashboardRequests): JsonResponse
    {
        $serviceRequests = ServiceRequest::query()
            ->where('app_user_id', $request->user()->id)
            ->with(['appUser', 'driver.branch', 'driver.company', 'services'])
            ->latest('id')
            ->get()
            ->map(fn (ServiceRequest $serviceRequest) => $dashboardRequests->requestResource($serviceRequest))
            ->values();

        return response()->success($serviceRequests);
    }

    /**
     * Owner-only detail. A request owned by another user resolves to 404 so its
     * existence is never leaked.
     */
    public function show(
        Request $request,
        ServiceRequest $serviceRequest,
        DashboardRequestController $dashboardRequests
    ): JsonResponse {
        if ((int) $serviceRequest->app_user_id !== (int) $request->user()->id) {
            abort(404);
        }

        return response()->success($dashboardRequests->requestResource(
            $serviceRequest->load(['appUser', 'driver.branch', 'driver.company', 'services'])
        ));
    }

    public function store(Request $request): JsonResponse
    {
        abort(404);
    }
}
