# Driver Prefix Transition

## Decision

Use `api/driver/v1` as the Laravel driver API root and keep `api/driver-app/v1` as a temporary compatibility alias during migration.

## Current Client State

The active driver client points to the Laravel single-port target:

- `yamak-drivers/src/plugins/AxiosConfig.ts`
- `http://localhost:8000/api/driver/v1/`

No active driver client source reference to `api/driver-app/v1` or old port `5004` was found in `yamak-drivers/src`.

## Laravel Route State

Laravel exposes both route roots:

- canonical: `api/driver/v1`
- legacy alias: `api/driver-app/v1`

Both roots map to the same driver controllers for:

- `POST auth/login`
- `GET drivers/profile`
- `PUT drivers/profile`
- `GET vehicles/owner`
- `PUT vehicles/location`
- `PUT vehicles/{vehicle}`
- `POST vehicles/link`
- `POST vehicles/unlink`

## Verification

- `php artisan route:list --path=api/driver` shows both canonical and legacy driver roots.
- `tests/Feature/DriverParityTest.php` covers canonical driver workflows and the legacy `api/driver-app/v1` owner alias.
- `tests/Feature/ApiRouteRegistrationTest.php` includes canonical driver routes and a legacy unlink route.
- `tests/PublicApi/PublicApiSmokeTest.php` includes the legacy driver root.

## Removal Rule

Do not remove `api/driver-app/v1` until the driver client rollout is complete and the route removal is approved as a separate cleanup task.
