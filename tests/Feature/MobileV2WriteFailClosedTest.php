<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.2C.4 — v2 POST consolidated to v1; endpoint removed.
 */
class MobileV2WriteFailClosedTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function validPayload(array $fixture): array
    {
        return [
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => [$fixture['services'][0]->id],
        ];
    }

    public function test_authenticated_v2_store_returns_404_after_consolidation(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);

        $this->postJson('/api/mobile/v2/requests', $this->validPayload($fixture))
            ->assertStatus(404);
    }

    public function test_v2_store_writes_nothing_and_sends_no_mail(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);

        $this->postJson('/api/mobile/v2/requests', $this->validPayload($fixture))
            ->assertStatus(404);

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_service', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
        Mail::assertNothingSent();
    }

    public function test_v2_update_verbs_are_not_registered(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);

        // The URI `requests/{serviceRequest}` matches the registered GET route,
        // so an unsupported verb is a method-not-allowed (405), not a write.
        foreach (['put', 'patch', 'delete'] as $verb) {
            $this->json($verb, '/api/mobile/v2/requests/1', [])
                ->assertStatus(405);
        }

        // The collection URI matches GET/POST, so an unsupported verb is also 405.
        $this->json('put', '/api/mobile/v2/requests', [])
            ->assertStatus(405);

        $this->assertDatabaseCount('service_requests', 0);
    }
}
