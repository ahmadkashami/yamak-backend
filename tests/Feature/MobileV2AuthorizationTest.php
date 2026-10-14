<?php

namespace Tests\Feature;

use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.2C.2 — HTTP authentication/authorization verification for the secured
 * mobile v2 request surface.
 *
 * Uses Laravel's in-process HTTP test client only. No running server is
 * contacted and no external ability/role model is invented: token abilities are
 * exactly what Sanctum::actingAs records.
 */
class MobileV2AuthorizationTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function makeDashboardUser(): User
    {
        return User::query()->create([
            'name' => 'Dashboard Admin',
            'email' => 'dash.'.uniqid('', true).'@example.com',
            'password' => '123456',
            'type' => 'admin',
        ]);
    }

    /**
     * @return array{0: \App\Models\ServiceRequest, 1: \App\Models\AppUser}
     */
    private function existingRequestOwnedByFreshUser(): array
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $request = ServiceRequest::query()->create([
            'platform' => 'mobile',
            'app_user_id' => $user->id,
            'driver_id' => $fixture['driver']->id,
            'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id,
            'status' => 'active',
        ]);

        return [$request, $user];
    }

    public function test_v2_index_requires_authentication(): void
    {
        $this->getJson('/api/mobile/v2/requests')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors', null);
    }

    public function test_v2_show_requires_authentication(): void
    {
        [$request] = $this->existingRequestOwnedByFreshUser();

        $this->getJson('/api/mobile/v2/requests/'.$request->id)
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_v2_store_requires_authentication(): void
    {
        $this->postJson('/api/mobile/v2/requests', [
            'vehicleId' => 1,
            'driverId' => 1,
            'services' => [1],
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_v2_index_allows_authenticated_mobile_app_user(): void
    {
        [, $user] = $this->existingRequestOwnedByFreshUser();
        Sanctum::actingAs($user, ['mobile']);

        $this->getJson('/api/mobile/v2/requests')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);
    }

    public function test_v2_index_rejects_dashboard_only_token(): void
    {
        Sanctum::actingAs($this->makeDashboardUser(), ['dashboard']);

        $this->getJson('/api/mobile/v2/requests')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'forbidden')
            // B2.2C.2 finding (reported, not fixed): response()->error() drops
            // its $code argument when $errors is null, so `errors` is null
            // rather than {code: FORBIDDEN}.
            ->assertJsonPath('errors', null);
    }

    public function test_v2_store_rejects_dashboard_only_token(): void
    {
        Sanctum::actingAs($this->makeDashboardUser(), ['dashboard']);

        $this->postJson('/api/mobile/v2/requests', ['vehicleId' => 1, 'driverId' => 1, 'services' => [1]])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'forbidden')
            ->assertJsonPath('errors', null);
    }

    public function test_v2_index_rejects_authenticated_user_without_mobile_ability(): void
    {
        [, $user] = $this->existingRequestOwnedByFreshUser();
        Sanctum::actingAs($user, ['web']);

        $this->getJson('/api/mobile/v2/requests')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'forbidden')
            ->assertJsonPath('errors', null);
    }

    public function test_v2_store_rejects_authenticated_user_without_mobile_ability(): void
    {
        [, $user] = $this->existingRequestOwnedByFreshUser();
        Sanctum::actingAs($user, []);

        $this->postJson('/api/mobile/v2/requests', ['vehicleId' => 1, 'driverId' => 1, 'services' => [1]])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'forbidden')
            ->assertJsonPath('errors', null);
    }
}
