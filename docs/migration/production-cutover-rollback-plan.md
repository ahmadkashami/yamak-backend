# Production Cutover And Rollback Plan

## Purpose

This plan defines the cutover and rollback controls for moving Yamak traffic from the legacy NestJS services to the Laravel backend.

## Cutover Baseline

Production domains:

| Client | Domain |
| --- | --- |
| Dashboard | https://dashboard.yamak-kw.com |
| Driver App | https://driver.yamak.com |
| Mobile App | https://mobile.yamak-kw.com |

Laravel target:

- One backend app.
- One production API host.
- Dashboard API under `/api/dashboard/v1`.
- Mobile API under `/api/mobile/v1`.
- Driver API under `/api/driver/v1`.
- Temporary driver alias under `/api/driver-app/v1`.

Legacy NestJS reference services:

- Dashboard reference service: port `5001`.
- Mobile reference service: port `5003`.
- Driver reference service: port `5004`.

The NestJS ports are reference-only. They are not the Laravel production target.

## Before Cutover

Required:

1. Production database backup is complete.
2. Previous deploy package or image is available.
3. DNS/load balancer rollback method is known.
4. Laravel environment secrets are installed through secure storage.
5. `php artisan config:cache` passes on the target environment.
6. `php artisan migrate --force` has an approved deployment window.
7. Critical Laravel tests pass for the release commit.
8. Client API base URLs point to the Laravel production API host.
9. External-service smoke tests are PASS or explicitly accepted as deferred with owner/date.
10. Support owner is available during the first production traffic window.

## Cutover Steps

1. Freeze backend deploys and client API route changes.
2. Confirm latest release commit and migration docs are linked in Notion.
3. Put Laravel into maintenance mode only if the hosting path requires it.
4. Deploy Laravel release.
5. Install production secrets.
6. Run config/cache commands.
7. Run migrations.
8. Restart workers and application services.
9. Point traffic to the Laravel API host.
10. Run backend, client, and provider smoke tests.
11. Monitor logs and support channels.

## Rollback Triggers

Rollback immediately if any of these happen and cannot be fixed inside the approved window:

- Dashboard login fails for approved admin accounts.
- Mobile login, OTP, signup, or request creation fails for approved test data.
- Driver login, profile, owner, location, status, link, or unlink flow fails.
- Database migration causes data integrity errors.
- Queue failures block required flows.
- Cloudinary/mail/WhatsApp/Firebase failures block required production workflows without accepted deferral.
- Error rate or latency exceeds the approved threshold.

## Rollback Steps

1. Stop new traffic to the Laravel release.
2. Repoint DNS/load balancer/proxy to the previous stable backend.
3. Stop queue workers for the failed release.
4. Restore the previous release package or image if needed.
5. If migrations are not backward compatible, restore the database from the verified backup.
6. Run legacy health checks.
7. Confirm clients can log in and complete critical flows.
8. Record the rollback reason, exact failure, affected commit, and data action in Notion.

## Data Safety

- Prefer backward-compatible migrations for cutover.
- Do not run destructive seeders or schema resets in production.
- Do not truncate or recreate production tables during cutover.
- If rollback requires database restore, make the restore decision explicit and record the backup timestamp.

## Post-cutover Watch List

Watch these flows during the first production window:

- Dashboard auth/profile.
- Dashboard company, branch, service, vehicle, driver, request, website, notification, and upload workflows.
- Mobile email/password auth, OTP, profile, user cars, notifications, services, vehicles, and requests.
- Driver auth/profile/vehicle owner/location/status/link/unlink.
- Cloudinary, mail, WhatsApp, Firebase, Redis queues, CORS, throttle behavior.

## Notion Reporting Template

```text
Cutover window:
Release commit:
Database backup timestamp:
Config cache: PASS/FAIL
Migration result: PASS/FAIL
Smoke result: PASS/FAIL
External service result: PASS/FAIL/BLOCKED
Rollback needed: YES/NO
Notes:
```
