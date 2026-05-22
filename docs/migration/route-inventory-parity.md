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
Status: migrated with critical parity coverage.

Covered by tests:

- API root health payload.
- Dashboard login/profile smoke path.
- Public file upload validation and fake Cloudinary success path.
- Dashboard stats.
- Company, service, branch, and website content happy paths.
- Branch-service and website-service relational linking.
- App-user listing and service request create/list flows.
- Notification send-to-user and send-to-topic with fake Firebase service.

Explicit risk acceptance:

- The 91 dashboard source routes are not all covered route-by-route yet.
- Low-frequency dashboard routes still need route-by-route expansion before production cutover, but critical happy paths are covered by `DashboardParityTest`.
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
- Google, Facebook, and Apple social signup/login with fake providers.
- Profile update, notification token/settings, user cars, notifications.
- Ads, app settings, services, vehicles, empty pagination, service vehicles, and requests.
- Mail and WhatsApp side effects use local fakes in tests.

Explicit risk acceptance:

- The legacy NestJS `/driver` mobile controller route is documented in the migration plan as an unclear route. Do not cut it over without confirming client usage.

## Cutover Rule

No source route should be cut over unless one of these is true:

- It has automated Laravel parity coverage.
- It is covered by a documented smoke test and client usage audit.
- It has explicit risk acceptance in this checklist or a linked Notion task.

Related cutover controls:

- `docs/migration/client-api-usage-audit.md`
- `docs/migration/production-deployment-runbook.md`
- `docs/migration/production-cutover-rollback-plan.md`
- `docs/migration/final-cutover-readiness.md`

## Latest Check

- Date: 2026-05-22
- Command: `php artisan route:list --path=api`
- Result: Laravel exposes 145 API routes, including compatibility aliases.
- Open follow-up: confirm usage of the unclear legacy mobile `/driver` route before cutover.
