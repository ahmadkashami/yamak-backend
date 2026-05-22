# Reverb Broadcasting Migration

## Decision

Laravel broadcasting for the migration target now uses Laravel Reverb instead of Pusher-specific configuration.

Official references:

- https://laravel.com/docs/13.x/reverb
- https://laravel.com/docs/13.x/broadcasting

## Laravel Configuration

Installed package:

```bash
composer require laravel/reverb
php artisan reverb:install
```

Committed configuration:

- `config/app.php` enables `App\Providers\BroadcastServiceProvider`.
- `config/broadcasting.php` defaults through `BROADCAST_CONNECTION` with a `BROADCAST_DRIVER` fallback for older local environments.
- `config/broadcasting.php` uses the `reverb` connection and removes the Pusher-specific connection block.
- `config/reverb.php` reads allowed origins from `REVERB_ALLOWED_ORIGINS`.
- `.env.example` documents `REVERB_*`, `REVERB_SERVER_*`, `REVERB_ALLOWED_ORIGINS`, and `VITE_REVERB_*` keys without real secrets.

## Required Environment Keys

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_SERVER_PATH=
REVERB_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:5174,http://localhost:8000
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Use real values only in the target secret manager or server environment.

## Local Commands

Run Laravel as usual:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Run the Reverb WebSocket server in a separate process:

```bash
php artisan reverb:start
```

For debug output during local checks:

```bash
php artisan reverb:start --debug
```

## Production Notes

- Reverb is a long-running process and must be managed separately from PHP-FPM or the web server.
- Run Reverb under the deployment process manager, such as Supervisor or the hosting platform's long-running process manager.
- After deploying code or changing Reverb configuration, run:

```bash
php artisan reverb:restart
```

- The process manager should restart the server after `reverb:restart` gracefully terminates existing connections.
- Set `REVERB_HOST`, `REVERB_PORT`, and `REVERB_SCHEME` to the public WebSocket endpoint that clients use.
- Set `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`, and `REVERB_SERVER_PATH` to the internal listener details.
- When TLS is terminated by Nginx, Apache, a load balancer, or a platform proxy, use the public HTTPS/WSS values in `REVERB_SCHEME` and proxy traffic to the internal Reverb listener.
- Restrict `REVERB_ALLOWED_ORIGINS` to the deployed dashboard, web, and mobile/webview origins in staging and production.
- Review open-file limits and process memory before production load because each WebSocket connection remains open.

## Smoke Checks

Run from `yamak-backend/`:

```bash
php artisan config:clear
php artisan config:cache
php artisan list reverb
php artisan test --filter=ReverbBroadcastingConfigTest
```

Do not start a production Reverb server until the target secret values, allowed origins, proxy, process manager, and restart command are configured.
