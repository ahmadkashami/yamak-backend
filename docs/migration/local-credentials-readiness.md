# Local Credentials Readiness

## Scope

This document records the sanitized local `.env` readiness check for staging/production credential collection. It does not include secret values.

## Last Checked

- Date: 2026-05-21
- Environment file checked: local `yamak-backend/.env`
- Verification commands:
  - `php artisan config:clear`
  - `php artisan config:cache`

## Configured Locally

- Database connection keys: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`
- Cloudinary keys: `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`
- Mail transport baseline: `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
- Redis baseline: `REDIS_HOST`, `REDIS_PORT`

## Not Configured Locally

- Database password: `DB_PASSWORD`
- SMTP credentials: `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_AUTH_USER`, `MAIL_AUTH_PASSWORD`
- WhatsApp Graph API: `WA_VERSION`, `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN`, `WA_AUTH_TEMPLATE`, `WA_LANGUAGE`
- OAuth/social provider credentials: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `APPLE_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY`
- Firebase/Expo: `FIREBASE_CREDENTIALS`, `FIREBASE_PROJECT_ID`, `EXPO_ACCESS_TOKEN`
- Redis deployment details: `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_DB`, `REDIS_CACHE_DB`, `REDIS_QUEUE`
- Deployment API settings: `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `API_THROTTLE_PER_MINUTE`

## Acceptance Status

- `.env.example` documents the expected keys.
- Real secrets remain outside Git.
- Local Cloudinary upload has been verified separately through `CloudinaryUploadService`.
- Local mail verification remains fake/local only because no SMTP credentials are configured.
- WhatsApp, OAuth, Firebase/Expo, Redis deployment, CORS, and throttle production smoke tests remain blocked until secure staging values are provided.

## Next Staging Steps

1. Store real values in the deployment secret manager or server environment.
2. Quote or escape any `.env` values containing whitespace before running Laravel config commands.
3. Run `php artisan config:clear` and `php artisan config:cache` on the target environment.
4. Run provider smoke tests for Cloudinary, SMTP, WhatsApp, Firebase/Expo, OAuth, and Redis-backed queues.
5. Keep output sanitized: never print tokens, private keys, app passwords, or service account JSON.

Use `docs/migration/secure-secrets-handoff.md` for the full staging/production secret handoff checklist.
