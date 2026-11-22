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
 * B3.7 / B7-02 — focused privacy regression for the legacy Mobile v2 GETs.
 *
 * The v2 outer envelope, flat collection shape, detail shape, owner scoping
 * and fail-closed POST are preserved. Only the serializer changed: v2 reads now
 * use the same customer-safe Mobile allowlists as v1 instead of Dashboard's
 * requestResource().
 */
class MobileV2RequestPrivacyTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $forbiddenFields = [
        '_id',
        'platform',
        'userId',
        'user',
        'driverId',
        'driver',
        'email',
        'fcmToken',
        'notificationToken',
        'notificationsEnabled',
        'location',
        'geoLocation',
        'licenseExpiredAt',
        'lastLogin',
        'actor_type',
        'actor_id',
        'reason_note',
        'idempotencyKey',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOwnedRequest(AppUser $owner, array $attributes = []): ServiceRequest
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

        return $request;
    }

    private function assertNoForbiddenFields(array $payload): void
    {
        foreach ($this->forbiddenFields as $field) {
            $this->assertArrayNotHasKey($field, $payload, 'Unexpected field: '.$field);
        }
    }

    public function test_index_is_owner_only_and_uses_the_mobile_summary_shape(): void
    {
        $ownerA = $this->makeAppUser();
        $ownerB = $this->makeAppUser();
        $own = $this->makeOwnedRequest($ownerA);
        $foreign = $this->makeOwnedRequest($ownerB);

        Sanctum::actingAs($ownerA, ['mobile']);

        $response = $this->getJson('/api/mobile/v2/requests')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        $rows = $response->json('data');
        $this->assertSame([$own->id], array_column($rows, 'id'));
        $this->assertNotContains($foreign->id, array_column($rows, 'id'));

        $this->assertEqualsCanonicalizing(
            ['id', 'channel', 'status', 'createdAt', 'vehicle_summary', 'services_summary'],
            array_keys($rows[0]),
        );
        $this->assertNoForbiddenFields($rows[0]);
    }

    public function test_show_is_owner_only_and_uses_the_mobile_detail_shape(): void
    {
        $owner = $this->makeAppUser();
        $own = $this->makeOwnedRequest($owner);

        DB::table('service_request_status_histories')->insert([
            'service_request_id' => $own->id,
            'from_status' => null,
            'to_status' => 'pending',
            'actor_type' => 'admin',
            'actor_id' => 9999,
            'reason_note' => 'internal-review-note',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($owner, ['mobile']);

        $response = $this->getJson('/api/mobile/v2/requests/'.$own->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $own->id);

        $detail = $response->json('data');
        $this->assertEqualsCanonicalizing([
            'id',
            'channel',
            'status',
            'createdAt',
            'vehicle_summary',
            'services_summary',
            'vehicle',
            'provider_name',
            'provider_phone',
            'owner_coords',
            'timeline',
        ], array_keys($detail));
        $this->assertNoForbiddenFields($detail);

        $this->assertCount(1, $detail['timeline']);
        $this->assertEqualsCanonicalizing(['toStatus', 'createdAt'], array_keys($detail['timeline'][0]));
        $this->assertSame('pending', $detail['timeline'][0]['toStatus']);
        $this->assertStringNotContainsString('internal-review-note', json_encode($detail));
        $this->assertStringNotContainsString('admin', json_encode($detail));
    }

    public function test_cross_owner_and_missing_details_return_the_same_safe_404(): void
    {
        $ownerA = $this->makeAppUser();
        $ownerB = $this->makeAppUser();
        $foreign = $this->makeOwnedRequest($ownerB);

        Sanctum::actingAs($ownerA, ['mobile']);

        $foreignResponse = $this->getJson('/api/mobile/v2/requests/'.$foreign->id)
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);

        $missingResponse = $this->getJson('/api/mobile/v2/requests/999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);

        $this->assertSame($foreignResponse->json(), $missingResponse->json());
        $this->assertNull($foreignResponse->json('data'));
    }
}
