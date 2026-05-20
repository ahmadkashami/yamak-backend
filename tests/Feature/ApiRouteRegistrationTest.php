<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiRouteRegistrationTest extends TestCase
{
    public function test_dashboard_api_routes_are_registered(): void
    {
        $this->assertRouteRegistered('api/dashboard/v1/dashboard', 'GET');
        $this->assertRouteRegistered('api/dashboard/v1/files-upload', 'POST');
    }

    public function test_driver_api_routes_are_registered(): void
    {
        $this->assertRouteRegistered('api/driver/v1/vehicles/unlink', 'POST');
        $this->assertRouteRegistered('api/driver-app/v1/vehicles/unlink', 'POST');
    }

    public function test_mobile_compatibility_routes_are_registered(): void
    {
        $this->assertRouteRegistered('api/mobile/client/v1/register', 'POST');
    }

    private function assertRouteRegistered(string $uri, string $method): void
    {
        $route = collect(Route::getRoutes())->first(function ($route) use ($uri, $method) {
            return $route->uri() === $uri && in_array($method, $route->methods(), true);
        });

        $this->assertNotNull($route, "{$method} {$uri} is not registered.");
    }
}
