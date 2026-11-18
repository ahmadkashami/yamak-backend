<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;

/**
 * Owner-only detail allowlist for GET /mobile/v1/requests/{id}.
 *
 * Extends the exact list allowlist, then adds only the fields approved for the
 * owner: fuller vehicle data, provider identity, the request-time coordinate
 * snapshot and the PUBLIC status timeline (toStatus + createdAt only).
 *
 * The timeline is intentionally built from `to_status` and `created_at` alone.
 * Actor columns, reason notes/codes, available reasons and allowed transitions
 * are never read from the model here, so they cannot leak through this surface.
 */
class MyRequestDetailResource extends MyRequestSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $serviceRequest = $this->resource;
        $driver = $serviceRequest->driver;

        // Historical requests have no captured coordinates. A missing value is
        // returned as NULL; it is never replaced by (0,0) or an inferred point.
        $hasCoordinates = $serviceRequest->latitude !== null
            || $serviceRequest->longitude !== null;

        return array_merge(parent::toArray($request), [
            'vehicle' => $serviceRequest->vehicle === null ? null : [
                'labelNumber' => $serviceRequest->vehicle->label_number,
                'modelName' => $serviceRequest->vehicle->model_name,
                'type' => $serviceRequest->vehicle->type,
                'status' => $serviceRequest->vehicle->status,
                'workStatus' => $serviceRequest->vehicle->work_status,
            ],
            'provider_name' => $driver?->name,
            'provider_phone' => $driver?->phone_num,
            'owner_coords' => $hasCoordinates ? [
                'latitude' => $serviceRequest->latitude === null
                    ? null
                    : (float) $serviceRequest->latitude,
                'longitude' => $serviceRequest->longitude === null
                    ? null
                    : (float) $serviceRequest->longitude,
            ] : null,
            'timeline' => $serviceRequest->statusHistories
                ->map(fn ($history) => [
                    'toStatus' => $history->to_status,
                    'createdAt' => optional($history->created_at)->toJSON(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
