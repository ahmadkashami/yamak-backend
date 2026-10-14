<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiRouteRegistrationTest extends TestCase
{
    public function test_dashboard_api_routes_are_registered(): void
    {
        foreach ([
            ['api/dashboard/v1/auth/login', 'POST'],
            ['api/dashboard/v1/sys-users/profile', 'GET'],
            ['api/dashboard/v1/sys-users', 'GET'],
            ['api/dashboard/v1/sys-users', 'POST'],
            ['api/dashboard/v1/dashboard', 'GET'],
            ['api/dashboard/v1/companies', 'GET'],
            ['api/dashboard/v1/companies', 'POST'],
            ['api/dashboard/v1/branches', 'GET'],
            ['api/dashboard/v1/branches', 'POST'],
            ['api/dashboard/v1/services', 'GET'],
            ['api/dashboard/v1/services', 'POST'],
            ['api/dashboard/v1/driver', 'GET'],
            ['api/dashboard/v1/driver', 'POST'],
            ['api/dashboard/v1/vehicles', 'GET'],
            ['api/dashboard/v1/vehicles', 'POST'],
            ['api/dashboard/v1/app-users', 'GET'],
            ['api/dashboard/v1/requests', 'GET'],
            ['api/dashboard/v1/adds', 'GET'],
            ['api/dashboard/v1/app-setting', 'GET'],
            ['api/dashboard/v1/files-upload', 'POST'],
            ['api/dashboard/v1/websites', 'GET'],
            ['api/dashboard/v1/websites/domains', 'GET'],
            ['api/dashboard/v1/website-features', 'GET'],
            ['api/dashboard/v1/website-sliders', 'GET'],
            ['api/dashboard/v1/website-themes/websites/{website}', 'GET'],
            ['api/dashboard/v1/website-carousels', 'GET'],
            ['api/dashboard/v1/onboardings', 'GET'],
            ['api/dashboard/v1/onboardings', 'POST'],
            ['api/dashboard/v1/onboardings/{onboarding}', 'GET'],
            ['api/dashboard/v1/onboardings/{onboarding}', 'PUT'],
            ['api/dashboard/v1/onboardings/{onboarding}', 'PATCH'],
            ['api/dashboard/v1/onboardings/{onboarding}', 'DELETE'],
            ['api/dashboard/v1/onboardings/{onboarding}/publish', 'POST'],
            ['api/dashboard/v1/onboardings/{onboarding}/activate', 'POST'],
            ['api/dashboard/v1/onboardings/{onboarding}/disable', 'POST'],
        ] as [$uri, $method]) {
            $this->assertRouteRegistered($uri, $method);
        }
    }

    public function test_driver_api_routes_are_registered(): void
    {
        foreach ([
            ['api/driver/v1/auth/login', 'POST'],
            ['api/driver/v1/drivers/profile', 'GET'],
            ['api/driver/v1/drivers/profile', 'PUT'],
            ['api/driver/v1/vehicles/owner', 'GET'],
            ['api/driver/v1/vehicles/location', 'PUT'],
            ['api/driver/v1/vehicles/link', 'POST'],
            ['api/driver/v1/vehicles/unlink', 'POST'],
            ['api/driver-app/v1/vehicles/unlink', 'POST'],
        ] as [$uri, $method]) {
            $this->assertRouteRegistered($uri, $method);
        }
    }

    public function test_mobile_compatibility_routes_are_registered(): void
    {
        foreach ([
            ['api/mobile/v1/auth/register', 'POST'],
            ['api/mobile/v1/auth/sendCode', 'POST'],
            ['api/mobile/v1/auth/checkCode', 'POST'],
            ['api/mobile/v1/auth/signup', 'POST'],
            ['api/mobile/v1/auth/login', 'POST'],
            ['api/mobile/v1/auth/verify-email', 'POST'],
            ['api/mobile/v1/auth/send-reset-password', 'POST'],
            ['api/mobile/v1/auth/check-reset-password', 'POST'],
            ['api/mobile/v1/auth/reset-password', 'POST'],
            ['api/mobile/v1/app-users/profile', 'GET'],
            ['api/mobile/v1/app-users/profile', 'PATCH'],
            ['api/mobile/v1/app-users/account', 'DELETE'],
            ['api/mobile/v1/user-cars', 'GET'],
            ['api/mobile/v1/user-cars', 'POST'],
            ['api/mobile/v1/user-notifications/mine', 'GET'],
            ['api/mobile/v1/app-setting', 'GET'],
            ['api/mobile/v1/services', 'GET'],
            ['api/mobile/v1/services/{service}/vehicles', 'GET'],
            ['api/mobile/v1/vehicles', 'GET'],
            ['api/mobile/v1/requests', 'POST'],
            ['api/mobile/v1/onboarding', 'GET'],
        ] as [$uri, $method]) {
            $this->assertRouteRegistered($uri, $method);
        }
    }

    public function test_onboarding_routes_are_scoped_and_middleware_correct(): void
    {
        foreach ([
            ['api/dashboard/v1/onboardings', 'GET'],
            ['api/dashboard/v1/onboardings', 'POST'],
            ['api/dashboard/v1/onboardings/{onboarding}', 'GET'],
            ['api/dashboard/v1/onboardings/{onboarding}', 'PATCH'],
            ['api/dashboard/v1/onboardings/{onboarding}', 'DELETE'],
            ['api/dashboard/v1/onboardings/{onboarding}/publish', 'POST'],
            ['api/dashboard/v1/onboardings/{onboarding}/activate', 'POST'],
            ['api/dashboard/v1/onboardings/{onboarding}/disable', 'POST'],
        ] as [$uri, $method]) {
            $middleware = $this->routeMiddleware($uri, $method);

            $this->assertContains('auth:sanctum', $middleware, "{$method} {$uri} must require sanctum auth.");
            $this->assertContains('api.area:dashboard', $middleware, "{$method} {$uri} must be restricted to the dashboard area.");
        }

        $mobileMiddleware = $this->routeMiddleware('api/mobile/v1/onboarding', 'GET');

        $this->assertNotContains('auth:sanctum', $mobileMiddleware, 'The mobile onboarding endpoint must stay public read-only.');
        $this->assertNotContains('api.area:mobile', $mobileMiddleware, 'The mobile onboarding endpoint must not require a mobile token.');

        $this->assertNull($this->findRoute('api/onboarding', 'GET'), 'onboarding must not be registered outside the mobile/v1 prefix.');
        $this->assertNull($this->findRoute('api/onboardings', 'GET'), 'onboardings must not be registered outside the dashboard/v1 prefix.');
    }

    private function findRoute(string $uri, string $method)
    {
        return collect(Route::getRoutes())->first(function ($route) use ($uri, $method) {
            return $route->uri() === $uri && in_array($method, $route->methods(), true);
        });
    }

    private function routeMiddleware(string $uri, string $method): array
    {
        $route = $this->findRoute($uri, $method);
        $this->assertNotNull($route, "{$method} {$uri} is not registered.");

        return $route->gatherMiddleware();
    }

    private function assertRouteRegistered(string $uri, string $method): void
    {
        $route = $this->findRoute($uri, $method);

        $this->assertNotNull($route, "{$method} {$uri} is not registered.");
    }
}
