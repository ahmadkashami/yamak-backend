<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dedicated response resource for authenticated Mobile request responses.
 *
 * Only the approved mobile contract is serialised. The resource never exposes
 * the Eloquent model wholesale, never delegates to a Dashboard resource and
 * never emits customer/driver credentials, coordinates, status history,
 * administrative reasons or provider/admin-only data.
 */
class ServiceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $serviceRequest = $this->resource;
        $driver = $serviceRequest->driver;

        return [
            'id' => (int) $serviceRequest->id,
            '_id' => (string) $serviceRequest->id,
            'channel' => $serviceRequest->channel,
            'status' => $serviceRequest->status,
            'vehicleId' => $serviceRequest->vehicle_id !== null
                ? (int) $serviceRequest->vehicle_id
                : null,
            'driverId' => $serviceRequest->driver_id !== null
                ? (string) $serviceRequest->driver_id
                : null,
            'driver' => $driver ? [
                'id' => (int) $driver->id,
                'name' => $driver->name,
                'phoneNum' => $driver->phone_num,
            ] : null,
            'services' => $serviceRequest->services
                ->map(fn ($service) => [
                    'id' => (int) $service->id,
                    'name' => [
                        'en' => $service->name_en,
                        'ar' => $service->name_ar,
                    ],
                ])
                ->values()
                ->all(),
            'createdAt' => optional($serviceRequest->created_at)->toJSON(),
        ];
    }
}
