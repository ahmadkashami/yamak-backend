# Final Cutover Readiness

## Status

**Status: Ready for Production Deployment**

All Laravel implementation work is complete. External service smoke tests for WhatsApp, SMTP, and Cloudinary have been manually verified. All 25 migration tasks, 7 epics, stories, and blockers are Done on the Notion board. No technical implementation work remains. Remaining items are deployment-time actions that must be performed during the production release window.

## Production Domains

| Client | Domain |
| --- | --- |
| Dashboard | https://dashboard.yamak-kw.com |
| Driver App | https://driver.yamak.com |
| Mobile App | https://mobile.yamak-kw.com |

## Ready Areas

- Laravel exposes dashboard, mobile, and driver APIs from one app on port 8000.
- Driver `/api/driver/v1` is the canonical prefix; `/api/driver-app/v1` remains as a temporary compatibility alias.
- Critical dashboard, mobile, and driver parity tests pass.
- Route inventory is documented in `docs/migration/route-inventory-parity.md`.
- Secure credential handoff is documented in `docs/migration/secure-secrets-handoff.md`.
- External-service smoke runbook is documented in `docs/migration/external-service-smoke-tests.md`.
- Firebase/Expo credential handling is env-driven; no Firebase private key is tracked in the repository.
- Client API usage is audited in `docs/migration/client-api-usage-audit.md`.
- Deployment and rollback controls are documented in `docs/migration/production-deployment-runbook.md` and `docs/migration/production-cutover-rollback-plan.md`.
- Laravel Reverb migration is documented in `docs/migration/reverb-broadcasting-migration.md`.

## Completed Gates

| Gate | Status | Evidence |
| --- | --- | --- |
| WhatsApp OTP smoke | ✅ PASS | Verified 2026-06-02: `WA_VERSION`, `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN` configured; real OTP delivered; message receipt confirmed. |
| SMTP mail smoke | ✅ PASS | Verified: Gmail SMTP configured; real email sent; approved inbox receipt confirmed. |
| Cloudinary upload smoke | ✅ PASS | Verified: credentials configured; real image uploaded; asset visible in Cloudinary account. |
| Local parity tests | ✅ PASS | 31 tests, 455 assertions pass after `php artisan test`. |
| Route registration | ✅ PASS | 143 API routes registered as of 2026-05-31. |
| Config cache | ✅ PASS | `php artisan config:cache` passes locally. |
| Client compatibility | ✅ PASS | `mobile-rn`, `yamak-dashboard`, `yamak-drivers`, `yamak-web` all point to port 8000 locally. |
| API route inventory | ✅ PASS | 133 NestJS source routes mapped; 143 Laravel routes (includes compatibility aliases). |
| Database schema | ✅ PASS | Normalized relational tables; no generic JSON document columns. |
| OAuth/social providers | ✅ Removed | Social login disabled for current phase; no smoke test required. |
| Mail local delivery | ✅ PASS | Manual `/test-mail` delivery confirmed 2026-05-26. |

## Accepted Deferrals

These items are accepted as deployment-time configuration, not blocking cutover readiness.

| Item | Decision |
| --- | --- |
| `DB_PASSWORD` | Install secure production value during production deployment. Local MySQL has no password. |
| `REDIS_USERNAME` / `REDIS_PASSWORD` | Install if production Redis requires authentication. Current local configuration continues. |

## Remaining Deployment-Time Actions

These must be completed in the production release window. They do not block the readiness declaration.

### Secrets to install on the production server

| Key | Action |
| --- | --- |
| `DB_PASSWORD` | Set secure production database password. |
| `FIREBASE_CREDENTIALS` | Install Firebase service account JSON outside the repository; set the server-local path in `.env`. |
| `FIREBASE_PROJECT_ID` | Set Firebase project ID. |
| `EXPO_ACCESS_TOKEN` | Set Expo access token for push notification fallback. |
| `REDIS_USERNAME` / `REDIS_PASSWORD` | Set if production Redis requires authentication. |
| `CORS_ALLOWED_ORIGINS` | `https://dashboard.yamak-kw.com,https://driver.yamak.com,https://mobile.yamak-kw.com` |
| `SANCTUM_STATEFUL_DOMAINS` | `dashboard.yamak-kw.com,driver.yamak.com,mobile.yamak-kw.com` |
| `REVERB_ALLOWED_ORIGINS` | `https://dashboard.yamak-kw.com,https://driver.yamak.com,https://mobile.yamak-kw.com` |
| `APP_URL` | Production API host URL. |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |

### Database

1. Take a full database backup before opening the migration window.
2. Verify the backup is restorable before pointing production traffic.
3. Run `php artisan migrate --force` in the approved deployment window.

### Deployment commands

```bash
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan config:cache
php artisan migrate --force
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan reverb:restart
php artisan up
```

### Post-deploy smoke tests

- `GET /api/dashboard/v1` → 200 ok
- `GET /api/mobile/v1` → 200 ok
- `GET /api/driver/v1` → 200 ok
- Dashboard login with approved admin account.
- Mobile login or OTP flow with approved test data.
- Driver login with approved test data.
- Firebase push notification to approved staging token (after `FIREBASE_CREDENTIALS` is installed).
- Expo push notification to approved staging token (after `EXPO_ACCESS_TOKEN` is installed).
- Reverb WebSocket connection from `https://dashboard.yamak-kw.com`.
- CORS check: request from `https://dashboard.yamak-kw.com` succeeds; request from an unapproved origin is blocked.

### Client environment updates

Set deployed client builds to point to the production API host:

- `yamak-dashboard` — update API base URL in `.env` to the production API host.
- `mobile-rn` — update API base URL in app config to the production API host.
- `yamak-drivers` — update API base URL in app config to the production API host.
- `yamak-web` — update API base URL to the production API host.

## Verification Snapshot

| Date | Check | Result |
| --- | --- | --- |
| 2026-05-31 | `php artisan route:list --path=api --json` | 143 API routes |
| 2026-05-31 | `php artisan config:cache` | PASS |
| 2026-05-31 | `php artisan test` | 31 tests, 455 assertions |
| 2026-06-02 | WhatsApp credentials installed; real OTP delivery | PASS |
| 2026-06-02 | SMTP delivery to approved inbox | PASS |
| 2026-06-02 | Cloudinary real upload confirmed | PASS |
| 2026-06-02 | Production domains recorded | dashboard.yamak-kw.com, driver.yamak.com, mobile.yamak-kw.com |
