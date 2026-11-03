<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

class MobileRequestIdempotencyTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    public function test_create_replay_and_conflict_preserve_one_request_and_its_services(): void
    {
        $fixture = $this->makeRequestFixture(2);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);
        $payload = [
            'vehicleId' => $fixture['vehicle']->id,
            'driverId' => $fixture['driver']->id,
            'services' => array_map(fn ($service) => $service->id, $fixture['services']),
        ];
        $this->withHeader('Idempotency-Key', (string) Str::uuid());

        $first = $this->postJson('/api/mobile/v1/requests', $payload)
            ->assertOk()->assertJsonPath('success', true);
        $links = DB::table('request_service')->orderBy('service_id')->get()->toArray();
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('request_service', 2);

        $this->postJson('/api/mobile/v1/requests', $payload)
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);

        $payload['services'] = [$fixture['services'][0]->id];
        $this->postJson('/api/mobile/v1/requests', $payload)
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'success' => false,
                'message' => 'The Idempotency-Key was already used with a different payload.',
                'data' => null,
                'errors' => null,
            ]);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('request_service', 2);
        $this->assertEquals($links, DB::table('request_service')->orderBy('service_id')->get()->toArray());
    }

    public function test_missing_required_field_remains_a_validation_error(): void
    {
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/mobile/v1/requests', ['services' => []])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
        $this->assertDatabaseCount('request_service', 0);
    }
}
