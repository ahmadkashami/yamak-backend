<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B3.3 — focused HTTP verification for the secure My Requests READ contract.
 *
 * These tests are added for a later isolated run. They use synthetic fixtures
 * and the in-process test client only; this task does not execute them against
 * the persistent integration database.
 */
class MobileMyRequestsReadTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: ServiceRequest, 1: array{company: mixed, branch: mixed, driver: mixed, vehicle: mixed, services: array}}
     */
    private function makeOwnedRequest(AppUser $owner, array $attributes = [], ?string $createdAt = null): array
    {
        $fixture = $this->makeRequestFixture(2);
        $fixture['driver']->update(['phone_num' => '965500000123']);

        $request = ServiceRequest::query()->create(array_merge([
            'platform' => 'mobile',
            'app_user_id' => $owner->id,
            'driver_id' => $fixture['driver']->id,
            'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id,
            'vehicle_id' => $fixture['vehicle']->id,
            'channel' => 'app',
            'status' => 'pending',
            'latitude' => 29.3759,
            'longitude' => 47.9774,
        ], $attributes));

        foreach ($fixture['services'] as $service) {
            $request->services()->attach($service->id);
        }

        if ($createdAt !== null) {
            DB::table('service_requests')->where('id', $request->id)->update(['created_at' => $createdAt]);
        }

        return [$request, $fixture];
    }

    public function test_reads_require_authentication(): void
    {
        $this->getJson('/api/mobile/v1/requests')
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->getJson('/api/mobile/v1/requests/1')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_reads_reject_wrong_token_ability(): void
    {
        Sanctum::actingAs($this->makeAppUser(), ['dashboard']);

        $this->getJson('/api/mobile/v1/requests')
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->getJson('/api/mobile/v1/requests/1')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_owner_a_sees_only_owner_a_requests(): void
    {
        $ownerA = $this->makeAppUser();
        $ownerB = $this->makeAppUser();
        [$own, $fixture] = $this->makeOwnedRequest($ownerA);
        $this->makeOwnedRequest($ownerB, [
            'channel' => 'whatsapp',
            'status' => 'contact_initiated',
        ]);

        Sanctum::actingAs($ownerA, ['mobile']);

        $response = $this->getJson('/api/mobile/v1/requests')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.metaData.totalCount', 1)
            ->assertJsonPath('data.metaData.currentCount', 1)
            ->assertJsonPath('data.list.0.id', $own->id)
            ->assertJsonPath('data.list.0.channel', 'app')
            ->assertJsonPath('data.list.0.status', 'pending')
            ->assertJsonPath('data.list.0.vehicle_summary.labelNumber', $fixture['vehicle']->label_number)
            ->assertJsonPath('data.list.0.services_summary.0.id', $fixture['services'][0]->id);

        $this->assertCount(1, $response->json('data.list'));
        $this->assertSame([$own->id], array_column($response->json('data.list'), 'id'));
    }

    public function test_foreign_and_nonexistent_details_return_identical_404(): void
    {
        $ownerA = $this->makeAppUser();
        $ownerB = $this->makeAppUser();
        [$foreign] = $this->makeOwnedRequest($ownerB);

        Sanctum::actingAs($ownerA, ['mobile']);

        $foreignResponse = $this->getJson('/api/mobile/v1/requests/'.$foreign->id)
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code', 'NOT_FOUND');

        $missingResponse = $this->getJson('/api/mobile/v1/requests/999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code', 'NOT_FOUND');

        $this->assertSame($foreignResponse->json(), $missingResponse->json());
    }

    public function test_list_pagination_ordering_and_limits(): void
    {
        $owner = $this->makeAppUser();
        $this->makeOwnedRequest($owner, ['channel' => 'phone'], '2026-01-01 00:00:00');
        $this->makeOwnedRequest($owner, ['channel' => 'whatsapp'], '2026-01-02 00:00:00');
        $this->makeOwnedRequest($owner, ['channel' => 'app'], '2026-01-02 00:00:00');

        // Resolve ids without depending on auto-increment gaps between fixtures.
        [$olderId, $sameDayId, $newestId] = ServiceRequest::query()->orderBy('id')->pluck('id')->all();

        Sanctum::actingAs($owner, ['mobile']);

        $this->getJson('/api/mobile/v1/requests')
            ->assertOk()
            ->assertJsonPath('data.metaData.currentPage', 1)
            ->assertJsonPath('data.metaData.itemsPerPage', 20)
            ->assertJsonPath('data.metaData.totalCount', 3);

        $pageOne = $this->getJson('/api/mobile/v1/requests?page=1&perPage=2')
            ->assertOk()
            ->assertJsonPath('data.metaData.currentCount', 2)
            ->assertJsonPath('data.metaData.itemsPerPage', 2)
            ->assertJsonPath('data.metaData.lastPage', 2);

        $this->assertSame([$newestId, $sameDayId], array_column($pageOne->json('data.list'), 'id'));

        $pageTwo = $this->getJson('/api/mobile/v1/requests?page=2&perPage=2')
            ->assertOk()
            ->assertJsonPath('data.metaData.currentCount', 1);

        $this->assertSame([$olderId], array_column($pageTwo->json('data.list'), 'id'));

        $this->getJson('/api/mobile/v1/requests?perPage=500')
            ->assertOk()
            ->assertJsonPath('data.metaData.itemsPerPage', 50);
    }

    public function test_list_uses_safe_field_allowlist(): void
    {
        $owner = $this->makeAppUser();
        [$request] = $this->makeOwnedRequest($owner);

        Sanctum::actingAs($owner, ['mobile']);

        $item = $this->getJson('/api/mobile/v1/requests')
            ->assertOk()
            ->json('data.list.0');

        $this->assertSame(
            ['id', 'channel', 'status', 'createdAt', 'vehicle_summary', 'services_summary'],
            array_keys($item),
        );

        foreach ([
            'latitude', 'longitude', 'owner_coords', 'provider_name', 'provider_phone',
            'userId', 'appUserId', 'driverId', 'platform', 'idempotencyKey',
            'statusHistory', 'timeline', 'availableReasons', 'allowedTransitions',
            'actorId', 'actorType', 'reasonNote', 'reasonCodes',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $item, "List leaked [{$forbidden}].");
        }

        $this->assertSame($request->id, $item['id']);
    }

    public function test_detail_includes_owner_fields_and_public_timeline_only(): void
    {
        $owner = $this->makeAppUser();
        [$request, $fixture] = $this->makeOwnedRequest($owner);

        // An administrative transition carrying private reason/actor data.
        $request->statusHistories()->create([
            'from_status' => 'pending',
            'to_status' => 'accepted',
            'actor_type' => 'admin',
            'actor_id' => 987654,
            'reason_note' => 'INTERNAL ONLY',
        ]);

        Sanctum::actingAs($owner, ['mobile']);

        $data = $this->getJson('/api/mobile/v1/requests/'.$request->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.provider_name', 'B2 Driver')
            ->assertJsonPath('data.provider_phone', '965500000123')
            ->assertJsonPath('data.owner_coords.latitude', 29.3759)
            ->assertJsonPath('data.owner_coords.longitude', 47.9774)
            ->json('data');

        $this->assertSame(
            [
                'id', 'channel', 'status', 'createdAt', 'vehicle_summary', 'services_summary',
                'vehicle', 'provider_name', 'provider_phone', 'owner_coords', 'timeline',
            ],
            array_keys($data),
        );

        foreach ([
            'statusHistory', 'availableReasons', 'allowedTransitions', 'actorId',
            'actorType', 'reasonNote', 'reasonCodes', 'idempotencyKey', 'userId', 'latitude', 'longitude',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, "Detail leaked [{$forbidden}].");
        }

        $this->assertCount(1, $data['timeline']);
        $this->assertSame(['toStatus', 'createdAt'], array_keys($data['timeline'][0]));
        $this->assertSame('accepted', $data['timeline'][0]['toStatus']);
        $this->assertSame($fixture['vehicle']->label_number, $data['vehicle']['labelNumber']);
    }

    public function test_detail_keeps_missing_historical_coordinates_null(): void
    {
        $owner = $this->makeAppUser();
        [$request] = $this->makeOwnedRequest($owner, [
            'latitude' => null,
            'longitude' => null,
        ]);

        Sanctum::actingAs($owner, ['mobile']);

        $response = $this->getJson('/api/mobile/v1/requests/'.$request->id)->assertOk();
        $this->assertNull($response->json('data.owner_coords'));
    }

    public function test_client_supplied_owner_query_parameter_cannot_change_scope(): void
    {
        $ownerA = $this->makeAppUser();
        $ownerB = $this->makeAppUser();
        [$own] = $this->makeOwnedRequest($ownerA);
        [$foreign] = $this->makeOwnedRequest($ownerB);

        Sanctum::actingAs($ownerA, ['mobile']);

        $this->getJson('/api/mobile/v1/requests?userId='.$ownerB->id)
            ->assertOk()
            ->assertJsonPath('data.metaData.totalCount', 1)
            ->assertJsonPath('data.list.0.id', $own->id);

        $this->getJson('/api/mobile/v1/requests/'.$foreign->id.'?userId='.$ownerA->id)
            ->assertStatus(404);
    }
}
