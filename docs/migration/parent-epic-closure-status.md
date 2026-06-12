# Parent Epic Closure Status

## Scope

This note records closure evidence for parent Notion stories/epics whose child implementation and parity tasks are already represented in Laravel.

## Driver Auth And Vehicle Access

Status: complete.

Evidence:

- Driver login returns Sanctum bearer tokens with the existing `accessToken` response contract.
- Driver profile read/update routes are present.
- Vehicle owner, location update, status update, link, and unlink routes are present.
- `api/driver/v1` is canonical.
- `api/driver-app/v1` remains as a temporary compatibility alias.
- `yamak-drivers/src/plugins/AxiosConfig.ts` points to `http://localhost:8000/api/driver/v1/`.
- `tests/Feature/DriverParityTest.php` covers login success/failure, profile, vehicle owner/location/status/link/unlink, legacy alias behavior, and wrong-area token rejection.

## Driver API Migration

Status: complete for migrated driver scope.

Evidence:

- Laravel route table exposes the driver controller surface under both canonical and temporary legacy roots.
- Driver parity coverage validates the active workflow behavior.
- Driver prefix transition is documented in `docs/migration/driver-prefix-transition.md`.

## Dashboard API Migration

Status: complete for critical dashboard cutover scope.

Evidence:

- Dashboard route inventory is tracked in `docs/migration/route-inventory-parity.md`.
- Dashboard API root, auth/profile, stats, company/service/branch/website flows, app users, requests, notifications, and upload behavior are covered by tests.
- `yamak-dashboard/.env` points to `http://localhost:8000/api/dashboard/v1/`.

Risk note:

- Low-frequency dashboard routes still benefit from more route-by-route expansion before production cutover. That risk is recorded in the route inventory parity checklist and does not block the parent epic's critical migration closure.

## Mobile API Migration

Status: complete for critical mobile cutover scope.

Evidence:

- Mobile API root, auth/OTP/signup/login/reset/social flows, profile, user cars, notifications, ads, app settings, services, vehicles, and requests are covered by `tests/Feature/MobileParityTest.php`.
- Mobile service/vehicle coordinate order and request mail side effects are covered.
- `yamak-mobile` local API configuration points to `http://localhost:8000/api/mobile/v1/`.
- Legacy mobile `/driver` risk is documented separately in `docs/migration/mobile-legacy-driver-routes.md`.

## API Parity Testing

Status: complete for critical happy paths.

Evidence:

- `tests/Feature/DashboardParityTest.php`
- `tests/Feature/DriverParityTest.php`
- `tests/Feature/MobileParityTest.php`
- `tests/Feature/ApiRouteRegistrationTest.php`
- `tests/PublicApi/PublicApiSmokeTest.php`
- `tests/Feature/SchemaGovernanceTest.php`
- `tests/Feature/SeedMongoDumpCommandTest.php`

Current verification command:

```bash
php artisan test
```

Latest verified result:

- 23 tests passed.
- 390 assertions passed.

## Remaining Blocked Work

The following work remains open because it requires runtime/staging services or real secret values:

- staging Cloudinary upload smoke test
- staging mail smoke test
- staging WhatsApp OTP smoke test
- extended live NestJS samples for upload, OTP, requests, and authenticated driver profile/location flows
- staging queue-worker execution
