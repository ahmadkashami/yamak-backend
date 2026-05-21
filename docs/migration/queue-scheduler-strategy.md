# Queue And Scheduler Strategy

## Decision

Keep external side effects synchronous until API parity is proven. Do not move mail, WhatsApp, Firebase, Expo, Cloudinary, or XLSX work into queued jobs as part of the parity migration unless a specific task requires it.

## Current Mode

Local and parity mode use:

```bash
QUEUE_CONNECTION=sync
```

This preserves current request timing and keeps controller tests deterministic.

## Staging Queue Path

When staging is ready to validate async side effects, use Redis or database queues through environment configuration:

```bash
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids
REDIS_QUEUE=default
php artisan queue:work redis --queue=default --tries=3 --backoff=10 --timeout=120
```

The migration set already includes Laravel's `failed_jobs` table.

## Candidate Jobs After Parity

Create explicit queued jobs only after the synchronous behavior is accepted:

- mail sends for auth/request notifications
- WhatsApp Graph API messages
- Firebase push notifications
- Expo push notifications
- heavy XLSX import/export work

## Scheduler Rule

Do not add cron or Laravel scheduler entries unless a source NestJS behavior requires scheduled execution. Current migrated API parity work does not require new scheduled jobs.

## Deployment Notes

- Keep one worker process per deployment environment at first.
- Use `--tries=3`, short backoff, and a bounded timeout.
- Monitor `failed_jobs` before increasing concurrency.
- Keep `QUEUE_CONNECTION=sync` available as a rollback switch for parity debugging.

## Verification

Before enabling queue workers in staging:

```bash
php artisan migrate --force
php artisan queue:failed
php artisan queue:work redis --queue=default --once --tries=1
```

The final staging acceptance check requires real staging credentials and a worker process managed by the deployment environment.
