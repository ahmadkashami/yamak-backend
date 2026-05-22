# Production Deployment Runbook

## Scope

This runbook covers the Laravel production deployment path for the migrated Yamak backend. It assumes the Laravel app serves dashboard, mobile, and driver APIs from one app and one API host.

Canonical API roots:

- Dashboard: `/api/dashboard/v1`
- Mobile: `/api/mobile/v1`
- Driver: `/api/driver/v1`
- Temporary driver compatibility alias: `/api/driver-app/v1`

## Pre-deploy Checks

Run these before opening a production deployment window:

```bash
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan config:cache
php artisan route:list --path=api
php artisan list reverb
php artisan test
```

Expected local route inventory as of 2026-05-22:

- 145 Laravel API routes.
- The route count includes compatibility aliases such as `/api/driver-app/v1`.
- The source NestJS inventory remains 133 routes in `nest-backend/LARAVEL_MIGRATION_PLAN.md`.

## Secret Installation

Install real values only through the target secret manager or server environment. Use:

- `docs/migration/secure-secrets-handoff.md`
- `docs/migration/credentials-checklist.md`
- `docs/migration/external-service-smoke-tests.md`

Do not copy secrets into Git, Notion, chat, screenshots, shell history, or deployment notes.

After updating any secret or environment value:

```bash
php artisan config:clear
php artisan config:cache
```

## Deployment Order

1. Confirm database backup is complete and restorable.
2. Confirm rollback package or previous release is available.
3. Put the application in maintenance mode if the deployment window requires a hard stop.
4. Pull or release the intended backend commit.
5. Install production Composer dependencies.
6. Install or refresh environment secrets.
7. Run Laravel config/cache commands.
8. Run database migrations with production approval.
9. Restart PHP-FPM, queue workers, scheduler workers, and web server services as applicable.
10. Restart the Reverb process after code or config changes.
11. Clear maintenance mode.
12. Run post-deploy smoke tests.

Suggested commands:

```bash
php artisan down --render="errors::503"
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

Only run commands that match the actual hosting stack. For example, `route:cache` should be skipped if dynamic route loading becomes incompatible in that environment.

## Post-deploy Smoke Tests

Backend smoke:

- `GET /api/dashboard/v1`
- `GET /api/mobile/v1`
- `GET /api/driver/v1`
- Dashboard login with an approved staging/admin account.
- Mobile login or OTP flow with approved test data.
- Driver login with approved test data.

External service smoke:

- Cloudinary dashboard upload using `POST /api/dashboard/v1/files-upload`.
- Mail delivery to an approved test inbox.
- WhatsApp template delivery to an approved test number.
- Firebase notification to an approved staging token or topic.
- OAuth/social login with approved provider test accounts.
- Redis/queue processing for one harmless job.
- Laravel Reverb WebSocket connection from an approved deployed client origin.

Use `docs/migration/external-service-smoke-tests.md` for redaction rules and provider-specific evidence format.

## Client Smoke Tests

Verify deployed clients call the Laravel API roots:

- `mobile-rn`: `/api/mobile/v1`
- `yamak-dashboard`: `/api/dashboard/v1`
- `yamak-drivers`: `/api/driver/v1`
- `yamak-web`: `/api/dashboard/v1` for current website content endpoints.

Do not remove `/api/driver-app/v1` until the driver app rollout is confirmed on `/api/driver/v1`.

## Go/No-go Criteria

Go only when:

- Config cache builds in production.
- Migrations complete successfully.
- Critical Laravel tests passed against the release candidate.
- Client base URLs point to the production Laravel API host.
- Provider smoke tests are PASS or explicitly deferred with owner/date.
- Reverb process manager, allowed origins, public WebSocket endpoint, and restart command are configured.
- Rollback path has been rehearsed or approved for the exact release.

No-go when:

- Production secrets are missing or fail config cache.
- Database backup restore path is unverified.
- Dashboard/mobile/driver login fails.
- Provider failures affect required production flows and have no accepted deferral.
- Reverb cannot start, restart, or accept connections from approved deployed clients.
- Client builds still point to legacy split NestJS ports.
