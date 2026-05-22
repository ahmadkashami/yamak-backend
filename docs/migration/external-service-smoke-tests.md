# External Service Smoke Tests

## Purpose

This runbook defines the sanitized staging smoke checks for the external service migration tasks. It covers Cloudinary, mail, WhatsApp Graph API, Firebase/Expo, OAuth/social providers, Redis queues, Laravel Reverb, and deployment API settings without exposing secret values.

Use this after real values are installed through `docs/migration/secure-secrets-handoff.md`.

## Safety Rules

- Use only approved staging accounts, inboxes, phone numbers, device tokens, and test files.
- Do not print tokens, passwords, private keys, service account JSON, API keys, signatures, public IDs, provider message IDs, or full external URLs.
- Do not use real customer phone numbers, emails, images, notification tokens, or social accounts.
- Run `php artisan config:clear` and `php artisan config:cache` after any environment change.
- Record only sanitized PASS, FAIL, or BLOCKED evidence in Notion.

## Pre-flight

Run from `yamak-backend/` on the target environment:

```bash
php artisan config:clear
php artisan config:cache
```

Confirm the application is using the expected environment and database without printing secrets:

```bash
php artisan about --only=environment
```

## Reporting Format

```text
Provider: WhatsApp Graph API
Environment: staging
Result: PASS
Code path: App\Services\WhatsAppService::sendAuthMessage
Evidence: approved test number received the configured template message
Secret values printed: NO
Notes: provider message identifiers redacted
```

## Cloudinary

Laravel code path:

- `App\Services\CloudinaryUploadService::upload`
- `App\Http\Controllers\Api\Dashboard\FilesUploadController::store`
- Dashboard endpoint: `POST /api/dashboard/v1/files-upload`
- Multipart key: `file`
- Default folder: `dev/images`

Smoke steps:

1. Prepare a tiny non-sensitive PNG or JPG under 1 MB.
2. Authenticate with a staging dashboard token.
3. Send a multipart request to `POST /api/dashboard/v1/files-upload` using the `file` key.
4. Verify a 200 JSON response with Cloudinary upload metadata.
5. Redact `asset_id`, `public_id`, `signature`, `api_key`, full URLs, and any account identifiers before recording evidence.

Pass criteria:

- Laravel returns `statusCode: 200`.
- The response contains a Cloudinary result with a secure delivery URL.
- No secret values or provider identifiers are printed in logs or Notion.

Known local status:

- Cloudinary is configured locally and has passed real upload checks.
- Staging/production should still run this smoke check after target secrets are installed.

## Mail

Laravel code paths:

- `App\Mail\UserRegistrationCodeMail`
- `App\Mail\UserResetPasswordCodeMail`
- `App\Mail\UserRequestOrderMail`
- `Illuminate\Support\Facades\Mail`

Smoke steps:

1. Configure the target mailer and sender values through secure environment storage.
2. Use an approved staging inbox as the recipient.
3. Trigger a safe auth or request flow that sends one of the existing Laravel mails.
4. Verify the inbox receives the message with the expected subject/body shape.
5. Redact recipient details if the staging inbox address is not meant for shared notes.

Pass criteria:

- `php artisan config:cache` passes after mail values are installed.
- One staging email is delivered to the approved test inbox.
- No app password, SMTP password, or auth username is printed.

## WhatsApp Graph API

Laravel code path:

- `App\Services\WhatsAppService::sendAuthMessage`
- `App\Services\WhatsAppService::sendMessage`
- Mobile signup and reset-password flows call `sendAuthMessage`.

Required keys:

- `WA_VERSION`
- `WA_PHONE_NUMBER_ID`
- `WA_ACCESS_TOKEN`
- `WA_AUTH_TEMPLATE`
- `WA_LANGUAGE`

Smoke steps:

1. Confirm the approved staging WhatsApp test number can receive template messages.
2. Trigger mobile signup or reset-password with safe test data, or call `sendAuthMessage` from a controlled staging session.
3. Verify the configured template arrives on the approved test number.
4. Redact phone number, provider message identifiers, access token, phone number ID, and template account details.

Pass criteria:

- Laravel does not log `WhatsApp credentials are not configured.`
- The Graph API accepts the request.
- The approved test number receives the expected template message.

## Firebase Notifications

Laravel code paths:

- `App\Services\FirebaseNotificationService::sendToToken`
- `App\Services\FirebaseNotificationService::sendToTopic`
- Dashboard notification controller methods for user and topic sends.

Required keys:

- `FIREBASE_CREDENTIALS`
- `FIREBASE_PROJECT_ID`

Smoke steps:

1. Install a staging Firebase service account JSON outside Git.
2. Set `FIREBASE_CREDENTIALS` to the server-local path for that JSON file.
3. Use an approved staging FCM token or topic.
4. Trigger a dashboard notification send through the existing notification endpoint or a controlled staging session.
5. Verify Firebase returns a message name and the test device/topic receives the notification.
6. Redact device tokens, service account paths if sensitive, project identifiers if needed, and Firebase message names.

Pass criteria:

- Laravel can create a Firebase OAuth access token.
- Firebase accepts the message send request.
- The approved test device or topic receives the notification.

## Expo Gap

`EXPO_ACCESS_TOKEN` is part of the credential checklist because the legacy migration plan lists Expo push credentials. As of this review, no Laravel `ExpoPushNotificationService` exists under `app/Services`.

Do not mark Expo delivery as passed only because `EXPO_ACCESS_TOKEN` exists. If Expo push is still required for production cutover, port or add the Laravel Expo delivery path first, then run a separate staging Expo smoke test.

## OAuth And Social Providers

Current Laravel mobile social auth has fake-provider test coverage for Google, Facebook, and Apple flows. Real provider smoke checks still require approved staging tokens or provider test accounts.

Smoke steps:

1. Configure provider values through secure environment storage when server-side values are required.
2. Use approved provider test accounts or tokens only.
3. Trigger each mobile social auth flow in staging.
4. Verify Laravel returns the expected mobile auth response and Sanctum token shape.
5. Redact provider tokens, account IDs, emails, and any JWT payloads before recording evidence.

Pass criteria:

- Google, Facebook, and Apple staging flows return compatible Laravel mobile auth responses.
- No provider token or private key is printed.

## Redis And Queues

Required keys:

- `REDIS_HOST`
- `REDIS_USERNAME`
- `REDIS_PASSWORD`
- `REDIS_PORT`
- `REDIS_DB`
- `REDIS_CACHE_DB`
- `REDIS_QUEUE`
- `QUEUE_CONNECTION`
- `QUEUE_FAILED_DRIVER`

Smoke steps:

1. Configure Redis and queue values through secure environment storage.
2. Run `php artisan config:cache`.
3. Dispatch one harmless staging job from an existing flow or a controlled staging session.
4. Process one job with the configured queue worker.
5. Confirm no failed job is created for the smoke payload.

Pass criteria:

- Redis connection succeeds.
- One harmless job is processed by the target queue worker.
- No Redis password, username, or host details are copied into Notion unless approved for operational notes.

## Laravel Reverb Broadcasting

Laravel code/config paths:

- `config/broadcasting.php`
- `config/reverb.php`
- `routes/channels.php`
- `App\Providers\BroadcastServiceProvider`

Required keys:

- `BROADCAST_CONNECTION`
- `REVERB_APP_ID`
- `REVERB_APP_KEY`
- `REVERB_APP_SECRET`
- `REVERB_HOST`
- `REVERB_PORT`
- `REVERB_SCHEME`
- `REVERB_SERVER_HOST`
- `REVERB_SERVER_PORT`
- `REVERB_SERVER_PATH`
- `REVERB_ALLOWED_ORIGINS`

Smoke steps:

1. Configure Reverb values through secure environment storage.
2. Restrict `REVERB_ALLOWED_ORIGINS` to the deployed dashboard, web, and approved mobile/webview origins.
3. Run `php artisan config:cache`.
4. Start or restart Reverb through the target process manager.
5. Verify `php artisan reverb:restart` gracefully restarts the managed process.
6. Open an approved staging client and verify the browser can connect to the public WebSocket endpoint.

Pass criteria:

- `BROADCAST_CONNECTION` resolves to `reverb`.
- `php artisan list reverb` shows `reverb:start` and `reverb:restart`.
- The process manager keeps the Reverb server running after restart.
- The public WebSocket endpoint works only from approved origins.
- No app key, app secret, host secret, or internal infrastructure detail is copied into Notion unless approved for operational notes.

## CORS And Throttle

Required keys:

- `SANCTUM_STATEFUL_DOMAINS`
- `CORS_ALLOWED_ORIGINS`
- `API_THROTTLE_PER_MINUTE`

Smoke steps:

1. Confirm the deployed dashboard, mobile/web origins, and API host values are installed.
2. Send a browser request from an allowed origin and verify CORS succeeds.
3. Send a request from an unapproved origin and verify it is not allowed.
4. Confirm the configured throttle value is appropriate for staging.

Pass criteria:

- Allowed origins work.
- Unapproved origins are not allowed.
- Throttle settings are documented for staging and production.

## Blocker Closure Criteria

| Task | Closure evidence |
| --- | --- |
| Missing Production Credentials | All required values are installed in secure staging/production storage and `php artisan config:cache` passes in each target environment. |
| Cloudinary Service Migration | Dashboard upload smoke passes in staging with sanitized Cloudinary evidence. |
| Mail Provider Migration | One approved staging email is delivered through the configured mail provider. |
| WhatsApp Graph API Migration | One approved staging WhatsApp template message is delivered through the Graph API. |
| Replace Pusher Broadcasting with Laravel Reverb | Reverb config cache, managed-process restart, allowed-origin, and WebSocket connection checks pass in staging. |
| External Services Migration | Cloudinary, mail, WhatsApp, Firebase/Expo decision, OAuth/social, Redis/queue, Reverb, CORS, and throttle checks are all PASS or explicitly accepted as deferred with owner/date. |
