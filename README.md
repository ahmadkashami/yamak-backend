# yamak-backend

Laravel 12 backend for the Yamak platform. This is the migration target replacing the legacy NestJS monorepo. It serves all three API areas — dashboard, mobile, and driver — from a single application on one port.

## Requirements

- PHP 8.2+
- Composer
- MySQL (for production) — tests use in-memory SQLite, no MySQL needed locally

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## Running

```bash
# API server
php artisan serve --host=127.0.0.1 --port=8000

# WebSocket server (Laravel Reverb)
php artisan reverb:start
```

## API Areas

All areas share the same server on port `8000` with the global `/api` prefix:

| Area | Prefix | Auth model |
|------|--------|------------|
| Dashboard (admin) | `/api/dashboard/v1` | `User` + Spatie roles |
| Mobile (customers) | `/api/mobile/v1` | `AppUser` |
| Driver | `/api/driver/v1` | `Driver` |

Legacy compatibility aliases that remain active:
- `/api/driver-app/v1` — old driver app prefix (mirrors `/api/driver/v1`)
- `/api/mobile/client/v1/register` — old register URL used by the mobile app

## Authentication

All routes use **Laravel Sanctum** bearer tokens. Protected routes additionally require the `api.area:{area}` middleware (`EnsureApiArea`), which validates that the token was issued with the matching ability (`dashboard`, `mobile`, or `driver`). Tokens from the wrong area are rejected with 403.

## Testing

```bash
# Run all test suites
php artisan test

# Single file
php artisan test tests/Feature/MobileParityTest.php

# Filter by test name
php artisan test --filter sendCode

# Named suite (Unit | Feature | Authorization | PartnerIsolation | Security | PublicApi)
php artisan test --testsuite Feature
```

Tests run against an in-memory SQLite database — no local MySQL required. External services (Firebase, Cloudinary, WhatsApp) are faked with `Http::fake()` or Mockery.

## Code Style

```bash
./vendor/bin/pint
```

## Key Dependencies

| Package | Purpose |
|---------|---------|
| `laravel/sanctum` | API bearer token authentication |
| `spatie/laravel-permission` | Role & permission management for dashboard users |
| `laravel/reverb` | WebSocket server (replaces Pusher) |
| `maatwebsite/excel` | Excel export |
| `guzzlehttp/guzzle` | HTTP client for external APIs |

## Services

| Service | Class | Notes |
|---------|-------|-------|
| Push notifications (FCM) | `FirebaseNotificationService` | Requires `FIREBASE_CREDENTIALS` env path |
| Push notifications (Expo) | `ExpoNotificationService` | Fallback for app users without FCM token |
| WhatsApp OTP | `WhatsAppService` | Meta Graph API; outbound only |
| Image uploads | `CloudinaryUploadService` | Uploads to `dev/images` folder |
| User profile updates | `UserProfileService` | Shared mobile profile logic |

## Environment Variables

Key prefixes — fill these in `.env`:

| Prefix | Service |
|--------|---------|
| `DB_*` | MySQL — database `yamakdb` |
| `CLOUDINARY_*` | Image uploads |
| `FIREBASE_CREDENTIALS`, `FIREBASE_PROJECT_ID` | Firebase FCM |
| `EXPO_ACCESS_TOKEN` | Expo Push fallback |
| `WA_*` | WhatsApp Cloud API |
| `MAIL_*` | SMTP mail |
| `REVERB_*` / `VITE_REVERB_*` | Laravel Reverb WebSocket |
| `REDIS_*` | Queue / cache |
| `CORS_ALLOWED_ORIGINS` | Allowed frontend origins |
| `API_THROTTLE_PER_MINUTE` | Rate limit (default 60) |

Never commit `.env`, Firebase service-account JSON files, or the `vendor/` directory.

## Response Format

All API responses mirror the NestJS envelope shape exactly. Do not change response key names, envelope structure, or HTTP status codes without verifying parity against the samples in `docs/migration/http-samples/`.

Notable invariant: `geoLocation.coordinates` is `[longitude, latitude]` (longitude first).
