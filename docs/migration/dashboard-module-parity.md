# Dashboard Module Parity

## Scope

This note records the dashboard module parity coverage added for the remaining dashboard migration tasks.

Covered task areas:

- Dashboard sys users and roles.
- Dashboard companies and branches.
- Dashboard common resources and locations.
- Dashboard website content.
- Dashboard client sync audit for `yamak-dashboard`.
- Public website content usage audit for `yamak-web`.

## Automated Coverage

`tests/Feature/DashboardParityTest.php` now covers:

- Dashboard login/profile token flow.
- Dashboard stats.
- Company create/list plus company branch, driver, and vehicle related routes.
- Branch create plus branch services and free-driver routes.
- Service create.
- Website create plus public `websites/domains` content aggregation.
- Website features, sliders, carousels, and default theme lookup.
- Sys-user create/list/update.
- Role create/list/update.
- Country create and country-state relationship routes.
- State bulk import and state-city relationship routes.
- City bulk import and listing routes.
- App users, requests, notifications, and upload validation behavior.

The dashboard parity test disables only Laravel's throttle middleware inside the test class. This keeps route behavior covered without letting local request-volume limits hide controller or response regressions.

## Client Evidence

`docs/migration/client-api-usage-audit.md` records the current client API roots:

- `yamak-dashboard` uses `VITE_BASE_URL=http://localhost:8000/api/dashboard/v1/`.
- `yamak-web` uses the dashboard-backed website content root for `websites/domains`.
- `yamak-drivers` uses `http://localhost:8000/api/driver/v1/`.

No additional dashboard/web/driver client code change was required in this pass.

## Migration Set Evidence

The local SQLite test path runs all migrations through `RefreshDatabase` and passes the full parity suite.

Local MySQL schema repair was also verified after an accidental cached-config test run targeted `Yamakdb_prod_test`:

- `php artisan migrate --force` returned `Nothing to migrate`.
- `php artisan db:seed --class=BaseDataSeeder --force` restored idempotent base data.
- `php artisan migrate:status` showed every tracked migration as `Ran`.

Do not use `migrate:fresh` for board verification. Use the SQLite test suite for destructive migration reset behavior, and use non-destructive `migrate --force` plus idempotent seeders for local MySQL recovery.
