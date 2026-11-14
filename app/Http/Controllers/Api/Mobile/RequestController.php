<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Controllers\Api\Dashboard\RequestController as DashboardRequestController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\ServiceRequestResource;
use App\Services\RequestCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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

    public function store(Request $request, RequestCreationService $service): JsonResponse
    {
        // B2.5C/C3: the canonical v1 creation body is tracked. Identity comes
        // only from the authenticated AppUser; client-supplied lifecycle or
        // transport fields are explicitly rejected.
        // `bail` guarantees the finite check runs before `between`: an INF/NAN
        // float (e.g. JSON 1e400) would otherwise make the range comparison
        // throw instead of producing a field-level validation error.
        $finite = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_finite((float) $value)) {
                $fail(sprintf('The %s must be a finite number.', $attribute));
            }
        };

        $data = $request->validate([
            'vehicleId' => ['required', 'integer', 'min:1'],
            'driverId' => ['required', 'integer', 'min:1'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['required', 'integer', 'min:1', 'distinct'],
            'channel' => ['required', 'string', Rule::in(['app', 'whatsapp', 'phone'])],
            'latitude' => ['bail', 'required', 'numeric', $finite, 'between:-90,90'],
            'longitude' => ['bail', 'required', 'numeric', $finite, 'between:-180,180'],
            'userId' => ['missing'],
            'status' => ['missing'],
            'platform' => ['missing'],
        ]);

        // The approved channels are exact values. Global TrimStrings rewrites
        // surrounding whitespace before validation, so a padded value such as
        // " app " would otherwise be silently normalised to "app". For JSON
        // bodies, compare the decoded raw value byte-for-byte with the value
        // that passed validation and reject any padded variant.
        if ($request->isJson()) {
            $raw = json_decode($request->getContent(), true);
            $rawChannel = is_array($raw) ? ($raw['channel'] ?? null) : null;

            if (is_string($rawChannel) && $rawChannel !== $data['channel']) {
                throw ValidationException::withMessages([
                    'channel' => ['The selected channel is invalid.'],
                ]);
            }
        }

        // The only cross-field rule: a (0, 0) pair is not a usable location.
        // Individual zero values are valid and stay valid.
        if ((float) $data['latitude'] === 0.0 && (float) $data['longitude'] === 0.0) {
            throw ValidationException::withMessages([
                'latitude' => ['Latitude and longitude cannot both be zero.'],
            ]);
        }

        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->input('idempotencyKey');

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            throw ValidationException::withMessages([
                'idempotencyKey' => ['The Idempotency-Key header is required.'],
            ]);
        }

        // Normalise/validate the key here so a malformed key is reported on its
        // own field, never folded into a channel/coordinate error. The service
        // repeats this defensively.
        try {
            $idempotencyKey = RequestCreationService::normalizeKey($idempotencyKey);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'idempotencyKey' => [$e->getMessage()],
            ]);
        }

        try {
            $result = $service->createTracked(
                $request->user(),
                (int) $data['vehicleId'],
                (int) $data['driverId'],
                $data['services'],
                $data['channel'],
                (float) $data['latitude'],
                (float) $data['longitude'],
                $idempotencyKey,
            );
        } catch (IdempotencyConflictException $e) {
            return response()->error($e->getMessage(), 409);
        }

        // ValidationException from eligibility, and any unexpected persistence
        // or integrity failure, propagate unchanged: 422 for genuine input
        // errors, 500 for internal faults. Neither is relabelled here.
        $serviceRequest = $result['request']->fresh([
            'driver:id,name,phone_num',
            'services:id,name_en,name_ar',
        ]);

        // C4: successful creation/replay is serialised by the dedicated Mobile
        // resource only. The Dashboard resource is never used on this route.
        return response()->success(
            (new ServiceRequestResource($serviceRequest))->resolve($request)
        );
    }

    public function show(ServiceRequest $request, DashboardRequestController $dashboardRequests): JsonResponse
    {
        return response()->success($dashboardRequests->requestResource(
            $request->load(['appUser', 'driver.branch', 'driver.company', 'services'])
        ));
    }

    public function update(Request $httpRequest, ServiceRequest $request, DashboardRequestController $dashboardRequests): JsonResponse
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

    public function destroy(ServiceRequest $request): JsonResponse
    {
        $request->delete();

        return response()->success(null);
    }
}
