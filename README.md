# Yamak Backend

Laravel backend for the Yamak migration.

## Local API

All local API areas use the same Laravel server port:

- Dashboard: `http://127.0.0.1:8000/api/dashboard/v1`
- Mobile: `http://127.0.0.1:8000/api/mobile/v1`
- Driver: `http://127.0.0.1:8000/api/driver/v1`

Run locally:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=127.0.0.1 --port=8000
```

Run tests:

```bash
php artisan test
```

The PHPUnit setup uses an in-memory SQLite database so migration smoke tests do not require a local MySQL testing database.

## Migration Notes

- This project replaces the old NestJS backend in `nest-backend`.
- Authentication target: Laravel Sanctum.
- Roles and permissions target: Spatie Laravel Permission.
- Database target: normalized MySQL schema in `yamakdb`.
- Do not commit real `.env` files, credentials, generated `vendor`, or runtime storage.
