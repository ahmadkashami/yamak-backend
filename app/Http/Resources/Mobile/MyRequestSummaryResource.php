<?php

namespace App\Http\Resources\Mobile;

use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allowlisted summary of one ServiceRequest for the authenticated owner.
 *
 * B3.3 mobile read contract. This resource is deliberately separate from
 * ServiceRequestResource (used by POST) and from every Dashboard resource.
 * It never serialises the Eloquent model wholesale and contains no
 * coordinates, driver phone, customer identity, status history, reason data,
 * transition matrix, internal actors or idempotency keys.
 *
 * @property ServiceRequest $resource
 */
class MyRequestSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $serviceRequest = $this->resource;

        return [
            'id' => (int) $serviceRequest->id,
            'channel' => $serviceRequest->channel,
            'status' => $serviceRequest->status,
            'createdAt' => optional($serviceRequest->created_at)->toJSON(),
            'vehicle_summary' => $serviceRequest->vehicle === null ? null : [
                'labelNumber' => $serviceRequest->vehicle->label_number,
                'modelName' => $serviceRequest->vehicle->model_name,
            ],
            'services_summary' => $serviceRequest->services
                ->map(fn ($service) => [
                    'id' => (int) $service->id,
                    'name' => [
                        'en' => $service->name_en,
                        'ar' => $service->name_ar,
                    ],
                ])
                ->values()
                ->all(),
        ];
    }
}
