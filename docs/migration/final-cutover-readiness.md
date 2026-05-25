# Final Cutover Readiness

## Status

Status: not ready for production cutover yet.

Reason: Laravel local parity and client single-port compatibility are documented, but staging/production provider credentials and external-service smoke evidence are still incomplete.

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

## Open Cutover Gates

| Gate | Status | Notes |
| --- | --- | --- |
| Production secrets installed | Blocked | Real values must be installed through secure deployment storage. |
| Laravel config cache on target | Pending | Run after target secrets are installed. |
| Database backup/restore rehearsal | Pending | Required before production migration window. |
| Cloudinary staging upload smoke | Pending | Local real upload passed; target environment smoke still required. |
| Mail staging delivery smoke | Pending | Requires real approved SMTP/provider credentials. |
| WhatsApp staging template smoke | Pending | Requires approved Graph API credentials and test number. |
| Firebase/Expo smoke | Pending | Firebase and Expo services exist; target credentials and approved test tokens are still required for staging smoke. |
| OAuth/social provider smoke | Pending | Requires approved provider test accounts/tokens. |
| Redis/queue worker smoke | Pending | Requires target Redis/queue setup. |
| Reverb WebSocket smoke | Pending | Requires target Reverb credentials, allowed origins, proxy, and process manager. |
| Production client environment values | Pending | Deployed builds must point to the Laravel production API host. |

## Go Decision

The project can move to cutover only after every open gate is either:

- PASS with sanitized evidence, or
- explicitly accepted as deferred with owner, date, and rollback impact.

Do not mark final cutover readiness as done while external-service credentials and target-environment smoke tests are missing.

## Verification Snapshot

Local verification performed for this readiness pass:

- `php artisan route:list --path=api` shows 145 API routes.
- Client API usage audit found local single-port compatibility for `mobile-rn`, `yamak-dashboard`, `yamak-drivers`, and `yamak-web`.

Target-environment verification is still required before production cutover.
