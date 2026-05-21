# Mobile Legacy Driver Routes

## Decision

Keep the Laravel `api/driver-app/v1` compatibility alias during migration, but do not add any mobile-specific `/driver` route handling.

The current `mobile-rn` source does not call legacy driver API roots directly. Driver API compatibility is still useful for the driver client and for any older deployed clients during cutover, so Laravel should keep the existing `api/driver-app/v1` alias until client rollout is complete.

## Audit Scope

Checked mobile client source and configuration for:

- `driver-app`
- `api/driver`
- `/driver`
- `driver/v1`

The only `mobile-rn` match was `vehicle.currentDriver.phoneNum` used to open a WhatsApp contact link from `VehiclesDetailsScreen.tsx`. It is data display behavior, not an API route.

## Laravel Route State

Laravel currently exposes both driver roots:

- `api/driver/v1`
- `api/driver-app/v1`

Both roots map to the same driver controllers for:

- auth login
- driver profile get/update
- vehicle owner
- vehicle location update
- vehicle link/unlink
- vehicle update

## Test Coverage

Current tests cover the compatibility surface:

- `tests/Feature/ApiRouteRegistrationTest.php` includes `api/driver-app/v1/vehicles/unlink`.
- `tests/Feature/DriverParityTest.php` covers the legacy prefix alias behavior.
- `tests/PublicApi/PublicApiSmokeTest.php` includes `api/driver-app/v1`.

## Follow-up Rule

Do not remove `api/driver-app/v1` until:

- all released driver clients point to `api/driver/v1`;
- mobile remains free of direct legacy driver API calls;
- route removal is explicitly approved as a separate cleanup task.
