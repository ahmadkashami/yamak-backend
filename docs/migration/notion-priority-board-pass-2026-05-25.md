# Notion Priority Board Pass - 2026-05-25

## Scope

This pass reviewed the next 15 priority Notion board items using current page properties, local repository evidence, and existing migration documents. It does not mark staging-only acceptance criteria as complete unless local evidence already satisfies the board item or the remaining work is explicitly documented as a target-environment gate.

## Verification Used

- `php artisan test` in `yamak-backend`
- `nest-backend/docs/migration/http-samples/`
- `nest-backend/docs/migration/extended-baseline-samples.md`
- `yamak-backend/docs/migration/final-cutover-readiness.md`
- `yamak-backend/docs/migration/external-service-smoke-tests.md`
- Notion board page properties and acceptance criteria

## Priority Items Processed

| # | Notion item | Priority | Board action | Evidence / remaining gate |
| --- | --- | --- | --- | --- |
| 1 | Blocker: Missing Production Credentials | Critical | Advanced, remains In progress | Key checklist and smoke-test docs exist; real target secrets still required. |
| 2 | Epic: Final Cutover Readiness | Critical | Advanced, remains In progress | Local parity is documented; target secrets, backup/restore rehearsal, provider smokes, and production client envs remain open. |
| 3 | Blocker: Provide Demo Credentials and Stable Test Data for Full Baseline | High | Marked Done | Local baseline artifacts now include dashboard, mobile, driver, OTP, request, upload, and driver vehicle samples. |
| 4 | Task: Cloudinary Service Migration | High | Advanced, remains In progress | Laravel service, fake tests, and local real upload are verified; target staging upload smoke still required. |
| 5 | Epic: External Services Migration | High | Advanced, remains In progress | Laravel services/docs exist for Cloudinary, mail, WhatsApp, Firebase/Expo, Reverb, OAuth notes, and Redis/queue strategy; target provider smokes remain. |
| 6 | Story: Queues and Scheduler Strategy | Medium | Advanced, remains In progress | Sync parity is preserved and worker docs exist; staging worker processing still requires target runtime. |
| 7 | Task: Replace Pusher Broadcasting with Laravel Reverb | High | Confirmed Done | Reverb config/docs/test are present; target WebSocket/process-manager smoke remains a cutover gate. |
| 8 | Task: Switch Laravel APIs and Clients to Single Port 8000 | High | Confirmed Done | Laravel and clients use local port 8000; route/client audit docs exist. |
| 9 | Story: Production Deployment Runbook | High | Confirmed Done | Deployment and rollback runbooks exist; staging rehearsal remains a final-cutover gate. |
| 10 | Task: Dashboard Notifications Migration | High | Confirmed Done | Firebase-first plus Expo fallback is covered by tests; target push smoke remains external-service gate. |
| 11 | Task: Firebase and Expo Credentials Security | Critical | Confirmed Done | No tracked Firebase private key file; env-driven path and negative tests exist. |
| 12 | Task: Dashboard Drivers and Vehicles Migration | High | Confirmed Done | Dashboard/driver relational workflows are covered by parity tests. |
| 13 | Task: Client Sync Audit for yamak-drivers | Critical | Confirmed Done | Driver client uses Laravel `api/driver/v1`; alias policy is documented. |
| 14 | Epic: API Parity Testing | Critical | Confirmed Done | Dashboard, mobile, driver, route registration, public API, schema, and seed import tests pass locally. |
| 15 | Task: Capture Extended NestJS Baseline Samples for Uploads OTP Requests and Driver Profile | Medium | Confirmed Done | Extended local baseline sample set is complete in the NestJS migration docs. |

## Remaining Active Board Gates

- Secure staging/production credentials and target `.env` installation.
- Target `php artisan config:cache` after real values are installed.
- Cloudinary staging HTTP upload smoke.
- Mail staging delivery smoke with bounded send completion and approved inbox receipt evidence; the latest CLI delivery attempt timed out despite configured SMTP values and reachable SMTP TCP connection.
- WhatsApp staging template smoke.
- Firebase/Expo staging push smoke.
- OAuth/social provider smoke with approved test accounts/tokens.
- Redis/queue worker smoke in target runtime.
- Reverb target WebSocket, allowed-origin, reverse-proxy/TLS, and process-manager smoke.
- Database backup/restore rehearsal before production cutover.
- Production client environment values for deployed builds.

## Decision

Do not mark final cutover ready until every active gate above is either PASS with sanitized evidence or explicitly accepted as deferred with owner, date, and rollback impact.
