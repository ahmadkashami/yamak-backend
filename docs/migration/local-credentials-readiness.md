# Local Credentials Readiness

## Scope

This document records the sanitized local `.env` readiness check for staging/production credential collection. It does not include secret values.

## Last Checked

- Date: 2026-05-26
- Environment file checked: local `yamak-backend/.env`
- Verification commands:
  - `php artisan config:clear`
  - `php artisan config:cache`
  - `php artisan test` — 31 passed, 455 assertions
  - `Test-NetConnection smtp.gmail.com 587` — TcpTestSucceeded: True
  - `Test-NetConnection api.cloudinary.com 443` — TcpTestSucceeded: True
  - `php artisan list reverb` — reverb:start and reverb:restart confirmed

## Configured Locally

- Database connection keys: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`
- Cloudinary: `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`, `CLOUDINARY_TIMEOUT` — set; `api.cloudinary.com:443` reachable; fake parity tests pass
- SMTP: `MAIL_MAILER`, `MAIL_HOST` (smtp.gmail.com), `MAIL_PORT` (587), `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_AUTH_USERNAME`, `MAIL_AUTH_PASSWORD`, `MAIL_ENCRYPTION` (tls), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` — all set; host reachable
- Reverb broadcasting: `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` — all set; `BROADCAST_CONNECTION=reverb`; `reverb:start` and `reverb:restart` commands available
- CORS: `CORS_ALLOWED_ORIGINS` — configured (open for local; tighten for production)
- Throttle: `API_THROTTLE_PER_MINUTE=60` — configured
- Sanctum stateful domains: `SANCTUM_STATEFUL_DOMAINS` — configured with localhost defaults
- Redis baseline: `REDIS_HOST`, `REDIS_PORT` — set

## Not Configured Locally (Requires Staging Secrets)

- Database password: `DB_PASSWORD` — empty (local MySQL has no password; production requires a secure value)
- WhatsApp Graph API: `WA_VERSION`, `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN` — not set; `WA_AUTH_TEMPLATE` and `WA_LANGUAGE` have config defaults
- Firebase: `FIREBASE_CREDENTIALS`, `FIREBASE_PROJECT_ID` — not set; unit tests cover missing-credentials failure path
- Expo: `EXPO_ACCESS_TOKEN` — not set; unit tests cover valid/invalid token paths
- Redis auth: `REDIS_USERNAME`, `REDIS_PASSWORD` — empty (local Redis requires no auth; production requires secure values)

## Acceptance Status

- `.env.example` documents all required keys.
- Real secrets remain outside Git.
- Cloudinary: configured locally; `api.cloudinary.com:443` reachable; fake parity coverage passes; real upload smoke previously passed (one timeout noted — re-run before closing production upload gate).
- SMTP: configured locally with Gmail SMTP; `smtp.gmail.com:587` reachable; credentials SET; delivery receipt still required before closing mail smoke gate.
- Reverb: configured locally; all keys SET; `BROADCAST_CONNECTION=reverb`; commands available; WebSocket connection from approved client still required before closing Reverb gate.
- CORS / Throttle / Sanctum: configured with local defaults; production-specific values (tighter origins, throttle) to be set in secure deployment storage.
- WhatsApp, Firebase/Expo, Redis auth: not configured locally; staging-only — require secure credential storage before smoke tests can run.
- OAuth/social provider smoke tests are not required for the current phase because social login is disabled.

## Next Staging Steps

1. Store real values in the deployment secret manager or server environment.
2. Quote or escape any `.env` values containing whitespace before running Laravel config commands.
3. Run `php artisan config:clear` and `php artisan config:cache` on the target environment.
4. Run provider smoke tests for Cloudinary, SMTP, WhatsApp, Firebase/Expo, and Redis-backed queues.
5. Keep output sanitized: never print tokens, private keys, app passwords, or service account JSON.

Use `docs/migration/secure-secrets-handoff.md` for the full staging/production secret handoff checklist and `docs/migration/external-service-smoke-tests.md` for the provider smoke-test runbook.
