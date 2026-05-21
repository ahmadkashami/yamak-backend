# Credentials Checklist

## Decision

Real secrets must stay out of Git. Use `.env.example` as the required key checklist and store real staging/production values only in the deployment secret manager or server environment.

## Covered Keys

The Laravel `.env.example` documents the migration credential surface for:

- MySQL: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- Cloudinary: `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`
- Legacy Cloudinary aliases: `Cloudinary_Cn`, `Cloudinary_API_KEY`, `Cloudinary_SECRET`
- SMTP: `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_AUTH_USER`, `MAIL_AUTH_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
- WhatsApp Graph API: `WA_VERSION`, `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN`, `WA_AUTH_TEMPLATE`, `WA_LANGUAGE`
- Google auth: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`
- Facebook/Apple placeholders: `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `APPLE_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY`
- Firebase: `FIREBASE_CREDENTIALS`, `FIREBASE_PROJECT_ID`
- Expo: `EXPO_ACCESS_TOKEN`
- Redis: `REDIS_HOST`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_PORT`, `REDIS_DB`, `REDIS_CACHE_DB`
- Sanctum/CORS/rate limit: `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `API_THROTTLE_PER_MINUTE`

## Current Provider Notes

- Facebook and Apple mobile social login currently validate client-provided access tokens and user info. The placeholder keys are documented so production setup has a reserved place if server-side provider app credentials are required later.
- CORS origins and API throttle values are environment-driven so staging and production can tighten them without code changes.
- Cloudinary config still supports legacy NestJS aliases during migration.

## Verification

Run before deployment:

```bash
php artisan config:clear
php artisan config:cache
php artisan test
```

Then perform the external smoke tests tracked by the Cloudinary, Mail, WhatsApp, Firebase/Expo, and OAuth tasks.
