<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Service;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Isolated, side-effect-free eligibility validation for the secured mobile v2
 * request contract (B2.2B.2).
 *
 * IMPORTANT: this class is intentionally NOT referenced by any controller or
 * route yet. `POST /api/mobile/v2/requests` remains fail-closed and returns
 * 503 NOT_ENABLED. Nothing here persists, syncs, or emails; it must only be
 * wired into the creation path in a later, explicitly approved step
 * (B2.2B.3 + activation). It performs reads only.
 *
 * Contract (no `userId`, no `status`, no location, no legacy `payload`):
 *   vehicleId: int   (required)
 *   driverId:  int   (required)
 *   services:  int[] (required, minimum 1, distinct)
 *
 * Authenticated identity is enforced at the route layer (`auth:sanctum` +
 * `api.area:mobile`); it is never accepted from the client for persistence.
 */
class RequestEligibilityValidator
{
    /**
     * Validate the v2 request input against the real domain relationships.
     *
     * @param  array<string, mixed>  $input
     * @return array{vehicle: Vehicle, driver: Driver, services: Collection<int, Service>}
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $validator = Validator::make($input, [
            'vehicleId' => ['required', 'integer'],
            'driverId' => ['required', 'integer'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['required', 'integer', 'distinct', 'exists:services,id'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        // Eloquent lookup (respects SoftDeletes; do not trust a raw exists()).
        $vehicle = Vehicle::query()->find($data['vehicleId']);

        if (! $vehicle) {
            throw ValidationException::withMessages([
                'vehicleId' => ['The selected vehicle was not found.'],
            ]);
        }

        // Availability rule mirrors the verified mobile vehicle listing rule
        // (GET /vehicles and GET /services/{service}/vehicles): work_status must
        // be "online" and a current driver must be assigned.
        if ($vehicle->work_status !== 'online' || $vehicle->current_driver_id === null) {
            throw ValidationException::withMessages([
                'vehicleId' => ['This vehicle is not currently available for requests.'],
            ]);
        }

        // `vehicles.current_driver_id` is the authoritative driver relation.
        if ((int) $vehicle->current_driver_id !== (int) $data['driverId']) {
            throw ValidationException::withMessages([
                'driverId' => ['The selected driver is not assigned to this vehicle.'],
            ]);
        }

        $driver = Driver::query()->find($data['driverId']);

        if (! $driver) {
            throw ValidationException::withMessages([
                'driverId' => ['The selected driver is not available.'],
            ]);
        }

        // Every selected service must be offered by this vehicle, via the real
        // `vehicle_service` pivot relation (never a hardcoded list).
        $serviceIds = array_values(array_unique(array_map('intval', $data['services'])));

        $services = $vehicle->services()
            ->whereIn('services.id', $serviceIds)
            ->get();

        if ($services->count() !== count($serviceIds)) {
            throw ValidationException::withMessages([
                'services' => ['One or more selected services are not offered by this vehicle.'],
            ]);
        }

        return [
            'vehicle' => $vehicle,
            'driver' => $driver,
            'services' => $services,
        ];
    }
}
