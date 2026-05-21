# Route Inventory Parity Checklist

## Source Inventory
The canonical NestJS route inventory is documented in `nest-backend/LARAVEL_MIGRATION_PLAN.md`.

Verified source totals:

| Area | Controllers | Source routes | Laravel status | Test status | Client status |
| --- | ---: | ---: | --- | --- | --- |
| Dashboard | 23 | 91 | Migrated as Laravel dashboard routes | Critical smoke coverage; full route-by-route coverage pending dashboard parity suite | `yamak-dashboard` base URL audit exists |
| Driver | 4 | 9 | Migrated under `api/driver/v1` plus temporary `api/driver-app/v1` alias | Driver parity suite passes | `yamak-drivers` uses Laravel `api/driver/v1`; existing dirty client changes were not touched |
| Mobile | 11 | 33 | Migrated under `api/mobile/v1` plus legacy register alias | Mobile parity suite passes for critical paths | `mobile-rn` usage audit exists; existing dirty client changes were not touched |
| Total | 38 | 133 | Tracked | Tracked by risk level | Tracked by client area |

Current Laravel route table:

- `php artisan route:list --path=api` shows 145 API routes.
- The Laravel count is higher than the NestJS source count because it includes the temporary driver legacy alias and Laravel compatibility aliases.

## Route Status By Area

### Dashboard Routes
Status: migrated with partial parity coverage.

Covered by tests:

- API root health payload.
- Dashboard login/profile smoke path.
- Public file upload validation and fake Cloudinary success path.

Explicit risk acceptance:

- The 91 dashboard source routes are not all covered route-by-route yet.
- Cutover for dashboard should wait for `Task: Dashboard Parity Test Suite`.
- Authorization behavior is documented separately in `docs/dashboard-authorization.md`.

### Driver Routes
Status: migrated and tested for critical workflows.

Covered by tests:

- Login success/failure.
- Profile get/update.
- Vehicle owner, location, status, link, and unlink flows.
- Temporary `api/driver-app/v1` alias.
- Wrong-area token rejection.

Explicit risk acceptance:

- Keep the legacy alias until all driver clients are verified on `api/driver/v1`.

### Mobile Routes
Status: migrated and tested for critical workflows.

Covered by tests:

- OTP send/check.
- Signup, login, email verification, reset-password, and legacy register.
- Google social signup/login with fake provider.
- Profile update, notification token/settings, user cars, notifications.
- Ads, app settings, services, vehicles, empty pagination, service vehicles, and requests.
- Mail and WhatsApp side effects use local fakes in tests.

Explicit risk acceptance:

- Mobile social provider tests still need Facebook and Apple coverage before full parity signoff.
- The legacy NestJS `/driver` mobile controller route is documented in the migration plan as an unclear route. Do not cut it over without confirming client usage.

## Cutover Rule

No source route should be cut over unless one of these is true:

- It has automated Laravel parity coverage.
- It is covered by a documented smoke test and client usage audit.
- It has explicit risk acceptance in this checklist or a linked Notion task.

## Latest Check

- Date: 2026-05-21
- Command: `php artisan route:list --path=api`
- Result: Laravel exposes 145 API routes, including compatibility aliases.
- Open follow-up: complete `Task: Dashboard Parity Test Suite` and extend social provider coverage.
