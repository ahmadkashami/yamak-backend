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
- Google auth: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`
- Facebook/Apple placeholders: `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `APPLE_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY`
- Firebase: `FIREBASE_CREDENTIALS`, `FIREBASE_PROJECT_ID`
- Expo: `EXPO_ACCESS_TOKEN`
- Redis: `REDIS_HOST`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_PORT`, `REDIS_DB`, `REDIS_CACHE_DB`
- Reverb: `BROADCAST_CONNECTION`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`, `REVERB_SERVER_PATH`, `REVERB_ALLOWED_ORIGINS`, `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME`
- Sanctum/CORS/rate limit: `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `API_THROTTLE_PER_MINUTE`

## Current Provider Notes

- Facebook and Apple mobile social login currently validate client-provided access tokens and user info. The placeholder keys are documented so production setup has a reserved place if server-side provider app credentials are required later.
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

Then perform the external smoke tests tracked by the Cloudinary, Mail, WhatsApp, Firebase/Expo, and OAuth tasks.

Use `docs/migration/external-service-smoke-tests.md` for the exact smoke-test steps and sanitized reporting format.

## Local Readiness Snapshot

See `docs/migration/local-credentials-readiness.md` for the latest sanitized local `.env` readiness audit.
See `docs/migration/secure-secrets-handoff.md` for the staging/production handoff checklist.
See `docs/migration/external-service-smoke-tests.md` for the shared external-service smoke-test runbook.

As of 2026-05-22:

- Cloudinary is configured locally with the same production-equivalent values used for testing, Laravel config cache passes, `api.cloudinary.com:443` is reachable, and fake upload parity tests pass. A previous Laravel service upload smoke passed, but the latest live service upload attempt timed out before receipt; keep `CLOUDINARY_TIMEOUT` configured and record the next successful bounded upload receipt before closing the target upload gate.
- SMTP is configured locally, Laravel config cache passes, and the configured SMTP host/port is reachable from this machine. A bounded CLI delivery attempt still timed out, so approved inbox receipt evidence is required before closing the target mail smoke gate.
- WhatsApp, OAuth/social provider, Firebase/Expo target tokens, Redis deployment, Reverb production host/origins/process manager, CORS, and throttle production values are not configured locally.
- `.env.example` remains the committed key checklist; real secrets must stay in secure deployment storage.
