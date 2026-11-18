<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestStatusHistory;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.5C/C3 — HTTP security verification for the retained v1 request path.
 *
 * v1 POST is preserved for the legacy mobile client but now speaks the tracked
 * creation contract: authenticated identity only, an approved channel and
 * request-time coordinates. B3.3 reworked index/show into owner-scoped secure
 * reads; update/destroy remain unexposed. All requests are served by the
 * in-process test client; nothing is sent to a running server.
 */
class MobileV1LegacySecurityTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * @param  array{vehicle: Vehicle, driver: Driver, services: array<int, Service>}  $fixture
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => [$fixture['services'][0]->id],
            'channel' => 'app',
            'latitude' => 29.3759,
            'longitude' => 47.9774,
        ], $overrides);
    }

    public function test_v1_store_requires_authentication(): void
    {
        $fixture = $this->makeRequestFixture(1);

        $this->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
        $this->assertDatabaseCount('service_request_status_histories', 0);
    }

    public function test_v1_store_accepts_authenticated_owner_under_tracked_contract(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);

        $this->withHeader('Idempotency-Key', 'a3b6f7c2-1d4e-4f6a-8b9c-0d1e2f3a4b5c')
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.driverId', (string) $fixture['driver']->id)
            ->assertJsonPath('data.channel', 'app')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('service_requests', 1);

        $request = ServiceRequest::query()->sole();
        $this->assertSame($user->id, (int) $request->app_user_id);
        $this->assertSame($fixture['vehicle']->id, (int) $request->vehicle_id);
        $this->assertSame('app', $request->channel);
        $this->assertSame('pending', $request->status);

        $history = ServiceRequestStatusHistory::query()->sole();
        $this->assertSame('app_user', $history->actor_type);
        $this->assertSame($user->id, (int) $history->actor_id);

        Mail::assertNothingSent();
    }

    public function test_v1_store_rejects_client_supplied_owner(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $victim = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);

        $this->withHeader('Idempotency-Key', 'b4c7d8e3-2e5f-4a7b-9c0d-1e2f3a4b5c6d')
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, ['userId' => $victim->id]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR')
            ->assertJsonFragment(['field' => 'userId']);

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
        Mail::assertNothingSent();
    }

    public function test_v1_store_rejects_wrong_token_ability(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['dashboard']);

        $this->withHeader('Idempotency-Key', 'c5d8e9f4-3f6a-4b8c-0d1e-2f3a4b5c6d7e')
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_v1_index_requires_authentication(): void
    {
        // B3.3: GET is now the owner-scoped My Requests read. It is exposed only
        // behind auth:sanctum + api.area:mobile (see MobileMyRequestsReadTest).
        $this->getJson('/api/mobile/v1/requests')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_v1_show_requires_authentication(): void
    {
        $this->getJson('/api/mobile/v1/requests/1')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_v1_update_and_destroy_are_not_exposed(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);
        $request = ServiceRequest::query()->create([
            'platform' => 'mobile',
            'app_user_id' => $user->id,
            'driver_id' => $fixture['driver']->id,
            'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id,
            'status' => 'pending',
        ]);

        foreach (['put', 'patch', 'delete'] as $verb) {
            $this->json($verb, '/api/mobile/v1/requests/'.$request->id, [])
                ->assertStatus(404);
        }

        $this->assertDatabaseCount('service_requests', 1);
    }
}
