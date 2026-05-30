# Credentials Checklist

## Decision

Real secrets must stay out of Git. Use `.env.example` as the required key checklist and store real staging/production values only in the deployment secret manager or server environment.

Use `docs/migration/secure-secrets-handoff.md` as the operator handoff for collecting and verifying real values without exposing them. Use `docs/migration/external-service-smoke-tests.md` for the sanitized provider smoke-test steps after those values are installed.

## Covered Keys

The Laravel `.env.example` documents the migration credential surface for:

- MySQL: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- Cloudinary: `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`, `CLOUDINARY_TIMEOUT`
- Legacy Cloudinary aliases: `Cloudinary_Cn`, `Cloudinary_API_KEY`, `Cloudinary_SECRET`
- SMTP: `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_AUTH_USER`, `MAIL_AUTH_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_TIMEOUT`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
- WhatsApp Graph API: `WA_VERSION`, `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN`, `WA_AUTH_TEMPLATE`, `WA_LANGUAGE`
- Firebase: `FIREBASE_CREDENTIALS`, `FIREBASE_PROJECT_ID`
- Expo: `EXPO_ACCESS_TOKEN`
- Redis: `REDIS_HOST`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_PORT`, `REDIS_DB`, `REDIS_CACHE_DB`
- Reverb: `BROADCAST_CONNECTION`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`, `REVERB_SERVER_PATH`, `REVERB_ALLOWED_ORIGINS`, `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME`
- Sanctum/CORS/rate limit: `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `API_THROTTLE_PER_MINUTE`

## Current Provider Notes

- Google, Facebook, and Apple social login are disabled for the current phase. Do not install OAuth/social provider keys unless that feature is explicitly re-enabled in a later task.
- CORS origins and API throttle values are environment-driven so staging and production can tighten them without code changes.
- Cloudinary config still supports legacy NestJS aliases during migration.
- Reverb replaces Pusher-specific broadcasting keys for the Laravel migration target. See `docs/migration/reverb-broadcasting-migration.md` for runtime and proxy notes.
- Firebase uses `FIREBASE_CREDENTIALS` as a server-local service account JSON path. Keep that JSON outside the repository or in secure deployment storage mounted at runtime; common Firebase credential filenames are ignored by Git as an extra local safety guard.
- Expo dashboard notification fallback uses `EXPO_ACCESS_TOKEN` when an app user has an Expo `notificationToken` but no `fcmToken`.

## Verification

Run before deployment:

```bash
php artisan config:clear
php artisan config:cache
php artisan test
```

Then perform the external smoke tests tracked by the Cloudinary, Mail, WhatsApp, Firebase/Expo, Redis/queue, Reverb, CORS, and throttle tasks.

Use `docs/migration/external-service-smoke-tests.md` for the exact smoke-test steps and sanitized reporting format.

## Local Readiness Snapshot

See `docs/migration/local-credentials-readiness.md` for the latest sanitized local `.env` readiness audit.
See `docs/migration/secure-secrets-handoff.md` for the staging/production handoff checklist.
See `docs/migration/external-service-smoke-tests.md` for the shared external-service smoke-test runbook.

As of 2026-05-26:

- All 31 tests pass (455 assertions) after `php artisan config:clear` and `php artisan config:cache`.
- Cloudinary: configured locally; `api.cloudinary.com:443` reachable; fake parity tests pass; real upload smoke previously confirmed; re-run before closing production upload gate.
- SMTP: configured locally (smtp.gmail.com:587, TLS); `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_AUTH_USERNAME`, `MAIL_AUTH_PASSWORD` SET; host reachable; approved inbox receipt evidence still required before closing mail smoke gate.
- Reverb: `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` all SET; `BROADCAST_CONNECTION=reverb`; `reverb:start` and `reverb:restart` commands confirmed; WebSocket client connection from approved origin still required before closing Reverb gate.
- CORS / Throttle / Sanctum: `CORS_ALLOWED_ORIGINS=*`, `API_THROTTLE_PER_MINUTE=60`, `SANCTUM_STATEFUL_DOMAINS` configured with local defaults; tighten for production in secure deployment storage.
- WhatsApp: `WA_VERSION`, `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN` not set locally — requires staging credentials.
- Firebase/Expo: `FIREBASE_CREDENTIALS`, `FIREBASE_PROJECT_ID`, `EXPO_ACCESS_TOKEN` not set locally — requires staging credentials; unit tests cover missing-credentials failure paths.
- Redis auth: `REDIS_USERNAME`, `REDIS_PASSWORD` empty (local Redis has no auth); requires secure values for production.
- OAuth/social provider credentials are not required while social login remains disabled.
- `.env.example` remains the committed key checklist; real secrets must stay in secure deployment storage.
