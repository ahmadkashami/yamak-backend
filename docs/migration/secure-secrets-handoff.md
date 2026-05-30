# Secure Secrets Handoff

## Purpose

This handoff defines how staging and production secrets should be collected for the Laravel migration without committing secret values to Git or printing them in logs.

## Storage Rule

Store real values only in the deployment secret manager or server environment. Do not store real values in:

- Git-tracked files
- Notion comments or task bodies
- Chat messages
- Test fixtures
- Local migration docs
- Screenshots or terminal output

If a value contains whitespace, wrap it in quotes in `.env` or store it as a single quoted/escaped secret value in the target environment. This is common for some mail app passwords and is required for `php artisan config:cache` to parse the environment file.

## Required Secret Groups

### Database

- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

### Cloudinary

- `CLOUDINARY_CLOUD_NAME`
- `CLOUDINARY_API_KEY`
- `CLOUDINARY_API_SECRET`

Legacy aliases are supported during migration but should not be the primary staging/production names:

- `Cloudinary_Cn`
- `Cloudinary_API_KEY`
- `Cloudinary_SECRET`

### Mail

- `MAIL_MAILER`
- `MAIL_HOST`
- `MAIL_PORT`
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_AUTH_USER`
- `MAIL_AUTH_PASSWORD`
- `MAIL_ENCRYPTION`
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`

### WhatsApp Graph API

- `WA_VERSION`
- `WA_PHONE_NUMBER_ID`
- `WA_ACCESS_TOKEN`
- `WA_AUTH_TEMPLATE`
- `WA_LANGUAGE`

### Firebase And Expo

- `FIREBASE_CREDENTIALS`
- `FIREBASE_PROJECT_ID`
- `EXPO_ACCESS_TOKEN`

### Redis And Queues

- `REDIS_HOST`
- `REDIS_USERNAME`
- `REDIS_PASSWORD`
- `REDIS_PORT`
- `REDIS_DB`
- `REDIS_CACHE_DB`
- `REDIS_QUEUE`
- `QUEUE_CONNECTION`
- `QUEUE_FAILED_DRIVER`

### Deployment API Settings

- `SANCTUM_STATEFUL_DOMAINS`
- `CORS_ALLOWED_ORIGINS`
- `API_THROTTLE_PER_MINUTE`

## Handoff Checklist

For each target environment:

1. Add values to the secret manager or server environment.
2. Confirm `.env.example` contains the key name without real values.
3. Quote or escape values with whitespace before writing them into an `.env` file.
4. Run `php artisan config:clear`.
5. Run `php artisan config:cache`.
6. Run the Laravel test suite.
7. Run provider smoke tests with sanitized output only.
8. Record the result in Notion without copying secret values.

## Smoke Tests To Record

- Database: migration or read-only connection check against the target database.
- Cloudinary: upload a tiny non-sensitive test image and verify `secure_url`.
- Mail: send one staging test email to an approved test inbox.
- WhatsApp: send one OTP/template message to an approved test number.
- OAuth/social: not required for the current phase; record as deferred/removed unless the feature is explicitly re-enabled.
- Firebase/Expo: send one approved staging notification or run a provider credential check.
- Redis/queue: dispatch and process one harmless queue job.
- CORS/throttle: verify allowed origins and configured API throttle value.

## Sanitized Reporting Format

Use this format in Notion or deployment notes:

```text
Provider: Cloudinary
Environment: staging
Result: PASS
Evidence: secure_url returned for tiny PNG upload
Secret values printed: NO
Notes: public ID redacted
```

## Current Local Status

See `docs/migration/local-credentials-readiness.md` for the latest sanitized local readiness snapshot and `docs/migration/external-service-smoke-tests.md` for the provider smoke-test runbook. As of 2026-05-25, OAuth/social provider credentials are not part of the required handoff because social login is disabled for the current phase; SMTP, WhatsApp, Firebase/Expo, Redis deployment values, CORS, and throttle production values still require secure staging/production setup.
