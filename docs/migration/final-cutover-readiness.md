# Final Cutover Readiness

## Status

Status: not ready for production cutover yet.

Reason: Laravel local parity, route inventory, config-cache behavior, and client single-port compatibility are documented and passing locally, but staging/production provider credentials and external-service smoke evidence are still incomplete.

## Ready Areas

- Laravel exposes dashboard, mobile, and driver APIs from one app.
- Local API roots are aligned with the single-port rule.
- Driver `/api/driver/v1` is canonical.
- Driver `/api/driver-app/v1` remains as a temporary compatibility alias.
- Critical dashboard, mobile, and driver parity tests exist.
- Route inventory is documented in `docs/migration/route-inventory-parity.md`.
- Secure credential handoff is documented in `docs/migration/secure-secrets-handoff.md`.
- External-service smoke steps are documented in `docs/migration/external-service-smoke-tests.md`.
- Firebase/Expo credential handling is env-driven with no tracked Firebase private key file in the Laravel repository.
- Client API usage is documented in `docs/migration/client-api-usage-audit.md`.
- Deployment and rollback controls are documented in:
  - `docs/migration/production-deployment-runbook.md`
  - `docs/migration/production-cutover-rollback-plan.md`
- Laravel Reverb migration controls are documented in `docs/migration/reverb-broadcasting-migration.md`.
- The 2026-05-25 priority board pass is documented in `docs/migration/notion-priority-board-pass-2026-05-25.md`.
- The 2026-05-31 local readiness refresh passed Laravel route registration, config cache, and automated tests.

## Open Cutover Gates

| Gate | Status | Notes |
| --- | --- | --- |
| Production secrets installed | Blocked | Real values must be installed through secure deployment storage. |
| Laravel config cache on target | Pending | Run after target secrets are installed. |
| Database backup/restore rehearsal | Pending | Required before production migration window. |
| Cloudinary staging upload smoke | Pending | Local Cloudinary config is production-equivalent for testing, API TCP connectivity is reachable, and fake upload tests pass; latest live service upload timed out before receipt, so a bounded target upload receipt is still required. |
| Mail staging delivery smoke | Local PASS / staging pending | SMTP config is installed locally, TCP connectivity is reachable, and the operator confirmed manual `/test-mail` delivery to the approved inbox on 2026-05-26. Repeat on staging with sanitized receipt evidence before production cutover. |
| WhatsApp staging template smoke | Pending | Requires approved Graph API credentials and test number. |
| Firebase/Expo smoke | Pending | Firebase and Expo services exist; target credentials and approved test tokens are still required for staging smoke. |
| OAuth/social provider smoke | Removed for current phase | Social login is disabled; Google/Facebook/Apple buttons, routes, provider config, and smoke requirements are not part of this cutover. |
| Redis/queue worker smoke | Pending | Requires target Redis/queue setup. |
| Reverb WebSocket smoke | Pending | Requires target Reverb credentials, allowed origins, proxy, and process manager. |
| Production client environment values | Pending | Deployed builds must point to the Laravel production API host. |

## Cutover Gate Evidence Matrix

| Gate | Required evidence before Done | Current evidence | Next action |
| --- | --- | --- | --- |
| Production secrets installed | Sanitized confirmation that target secrets are installed in deployment storage. | Secure handoff checklist exists. | Install real staging/production values outside Git, Notion, and chat. |
| Laravel config cache on target | `php artisan config:cache` succeeds on the target environment after real values are installed. | Local `php artisan config:cache` passed on 2026-05-31. | Re-run on staging/production after target `.env` installation. |
| Database backup/restore rehearsal | Timestamped backup and restore rehearsal result with sanitized database name/size. | Runbook exists. | Execute rehearsal before production migration window. |
| Cloudinary staging upload smoke | Bounded upload completion with sanitized receipt evidence. | Local fake tests pass; local API connectivity and configured timeout are documented. | Run staging upload smoke with real target credentials. |
| Mail staging delivery smoke | Approved staging inbox receipt plus sanitized send metadata. | Local `/test-mail` delivery was operator-confirmed on 2026-05-26. | Repeat on staging with approved recipient. |
| WhatsApp staging template smoke | Approved template send result to a test number with sanitized Graph API metadata. | Laravel Graph API service and fake HTTP coverage exist. | Run with staging credentials and approved test number. |
| Firebase/Expo smoke | FCM and Expo staging delivery result using approved test tokens/topics. | Credential guards, Firebase service, Expo fallback, and tests exist. | Install target credentials and run push smoke. |
| Redis/queue worker smoke | Queue dispatch and worker-processing evidence from target runtime. | Queue/scheduler strategy is documented with sync parity locally. | Configure target Redis/worker and process a test job. |
| Reverb WebSocket smoke | Target WebSocket connection and broadcast evidence behind proxy/TLS/process manager. | Reverb config/docs/test exist locally. | Configure target Reverb app, allowed origins, proxy, TLS, and supervisor. |
| Production client environment values | Deployed client builds point to the production Laravel API host. | Local client API usage audit confirms single-port compatibility. | Set deployed build env values during release preparation. |

## Go Decision

The project can move to cutover only after every open gate is either:

- PASS with sanitized evidence, or
- explicitly accepted as deferred with owner, date, and rollback impact.

Do not mark final cutover readiness as done while external-service credentials and target-environment smoke tests are missing.

## Verification Snapshot

Local verification performed for this readiness pass:

- 2026-05-31: `php artisan route:list --path=api --json` shows 143 API routes after removing the current-phase social auth endpoints.
- 2026-05-31: `php artisan config:cache` passes locally.
- 2026-05-31: `php artisan test` passes locally with 31 tests and 455 assertions.
- Client API usage audit found local single-port compatibility for `mobile-rn`, `yamak-dashboard`, `yamak-drivers`, and `yamak-web`.

Target-environment verification is still required before production cutover.
