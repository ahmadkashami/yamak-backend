<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Dashboard\RequestController as DashboardRequestController;
use App\Http\Controllers\Controller;
use App\Mail\UserRequestOrderMail;
use App\Models\Driver;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class RequestController extends Controller
{
    public function index(DashboardRequestController $dashboardRequests): \Illuminate\Http\JsonResponse
    {
        return response()->success(
            ServiceRequest::query()
                ->with(['appUser', 'driver.branch', 'driver.company', 'services'])
                ->latest('id')
                ->get()
                ->map(fn (ServiceRequest $serviceRequest) => $dashboardRequests->requestResource($serviceRequest))
                ->values()
        );
    }

    public function store(Request $request, DashboardRequestController $dashboardRequests): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'driverId' => ['required', 'integer', 'exists:drivers,id'],
            'userId' => ['required', 'integer', 'exists:app_users,id'],
            'services' => ['nullable', 'array'],
            'services.*' => ['integer', 'exists:services,id'],
            'platform' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        // B2.2B.1a ownership guard: a mobile user may only create requests for
        // themselves. The authenticated AppUser is authoritative; a client
        // supplied userId that does not match is rejected (403) before any
        // persistence or email. The userId field is kept for Legacy payload
        // compatibility and validated for existence above.
        if ((int) $data['userId'] !== (int) $request->user()->id) {
            abort(403);
        }

        $driver = Driver::query()->findOrFail($data['driverId']);
        $serviceRequest = ServiceRequest::query()->create([
            'platform' => $data['platform'] ?? 'mobile',
            'app_user_id' => $request->user()->id,
            'driver_id' => $driver->id,
            'company_id' => $driver->company_id,
            'branch_id' => $driver->branch_id,
            'status' => $data['status'] ?? 'active',
        ]);

        $serviceRequest->services()->sync($data['services'] ?? []);

        if ($driver->email) {
            Mail::to($driver->email)->send(new UserRequestOrderMail(now()));
        }

        return response()->success($dashboardRequests->requestResource(
            $serviceRequest->fresh(['appUser', 'driver.branch', 'driver.company', 'services'])
        ));
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
