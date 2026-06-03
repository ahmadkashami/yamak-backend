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

## Deployment-Time Secrets (Not Configured Locally)

- WhatsApp Graph API: `WA_VERSION=v25.0`, `WA_PHONE_NUMBER_ID` and `WA_ACCESS_TOKEN` are now set locally (2026-06-02). Real OTP delivery verified manually.
- Database password: `DB_PASSWORD` — empty locally. **Accepted deferral** — install secure production value during production deployment.
- Firebase: `FIREBASE_CREDENTIALS`, `FIREBASE_PROJECT_ID` — not set; deployment-time install required. Unit tests cover missing-credentials failure path.
- Expo: `EXPO_ACCESS_TOKEN` — not set; deployment-time install required. Unit tests cover valid/invalid token paths.
- Redis auth: `REDIS_USERNAME`, `REDIS_PASSWORD` — empty. **Accepted deferral** — install if production Redis requires authentication.

## Acceptance Status (Updated 2026-06-02)

- `.env.example` documents all required keys including production domain comments.
- Real secrets remain outside Git.
- Cloudinary: ✅ PASS — credentials configured; real upload verified; visible in Cloudinary account.
- SMTP: ✅ PASS — Gmail SMTP configured; `smtp.gmail.com:587` reachable; real email sent; inbox receipt confirmed.
- WhatsApp: ✅ PASS — `WA_VERSION=v25.0`, `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN` set (2026-06-02); real OTP delivery verified.
- Reverb: configured locally; all keys SET; `BROADCAST_CONNECTION=reverb`; commands available; WebSocket connection from production origin required at deployment time.
- CORS / Throttle / Sanctum: production domains documented — `dashboard.yamak-kw.com`, `driver.yamak.com`, `mobile.yamak-kw.com`; set in production `.env` at deployment time.
- Firebase/Expo: not set locally; deployment-time install required; unit tests cover failure paths.
- Redis auth / DB password: accepted deferrals — install at production deployment.
- OAuth/social provider smoke tests are not required for the current phase because social login is disabled.

**Overall: Ready for Production Deployment.**

## Next Staging Steps

1. Store real values in the deployment secret manager or server environment.
2. Quote or escape any `.env` values containing whitespace before running Laravel config commands.
3. Run `php artisan config:clear` and `php artisan config:cache` on the target environment.
4. Run provider smoke tests for Cloudinary, SMTP, WhatsApp, Firebase/Expo, and Redis-backed queues.
5. Keep output sanitized: never print tokens, private keys, app passwords, or service account JSON.

Use `docs/migration/secure-secrets-handoff.md` for the full staging/production secret handoff checklist and `docs/migration/external-service-smoke-tests.md` for the provider smoke-test runbook.
