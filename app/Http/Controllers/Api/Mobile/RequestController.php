<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Controllers\Api\Dashboard\RequestController as DashboardRequestController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\MyRequestDetailResource;
use App\Http\Resources\Mobile\MyRequestSummaryResource;
use App\Http\Resources\Mobile\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Services\RequestCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RequestController extends Controller
{
    /**
     * B3.3 — owner-scoped My Requests listing.
     *
     * Identity is taken only from the Sanctum-authenticated AppUser. The owner
     * filter is applied BEFORE pagination and BEFORE serialisation; no client
     * supplied userId is ever read. The dedicated mobile resource is the only
     * serializer used here (never a Dashboard resource).
     */
    public function index(Request $request): JsonResponse
    {
        $page = $this->positiveInt($request->query('page'), 1, PHP_INT_MAX);
        $perPage = $this->positiveInt($request->query('perPage'), 20, 50);

        $requests = ServiceRequest::query()
            ->where('app_user_id', $request->user()->id)
            ->with(['vehicle', 'services'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->success([
            'list' => $requests->getCollection()
                ->map(fn (ServiceRequest $serviceRequest) => (new MyRequestSummaryResource($serviceRequest))->resolve($request))
                ->values()
                ->all(),
            'metaData' => [
                'totalCount' => $requests->total(),
                'currentCount' => $requests->count(),
                'currentPage' => $requests->currentPage(),
                'itemsPerPage' => $requests->perPage(),
                'lastPage' => $requests->lastPage(),
            ],
        ]);
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

    /**
     * B3.3 — owner-only detail read.
     *
     * The owner predicate is part of the lookup itself, so a foreign or
     * nonexistent id is indistinguishable: both return the standard 404 error
     * envelope. No existence information is revealed before serialisation.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $serviceRequest = ServiceRequest::query()
            ->where('id', $id)
            ->where('app_user_id', $request->user()->id)
            ->with([
                'vehicle',
                'driver',
                'services',
                'statusHistories' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            ])
            ->first();

        if ($serviceRequest === null) {
            return response()->error('not_found', 404, null, 'NOT_FOUND');
        }

        return response()->success(
            (new MyRequestDetailResource($serviceRequest))->resolve($request)
        );
    }

    /**
     * Normalise an optional positive integer query value. Invalid values fall
     * back to the documented default; values above $maximum are clamped.
     */
    private function positiveInt(mixed $value, int $default, int $maximum): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        return min($maximum, max(1, (int) $value));
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
