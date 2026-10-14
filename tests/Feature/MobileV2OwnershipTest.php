<?php

namespace Tests\Feature;

use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.2C.2 — HTTP owner-scoping verification for mobile v2 request reads.
 *
 * Identity is always the Sanctum-authenticated AppUser. Cross-user access must
 * be indistinguishable from "not found" (404) and list responses must never
 * leak another user's rows.
 */
class MobileV2OwnershipTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function createRequest(array $fixture, int $appUserId): ServiceRequest
    {
        return ServiceRequest::query()->create([
            'platform' => 'mobile',
            'app_user_id' => $appUserId,
            'driver_id' => $fixture['driver']->id,
            'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id,
            'status' => 'active',
        ]);
    }

    public function test_index_returns_only_the_authenticated_users_requests(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $userA = $this->makeAppUser();
        $userB = $this->makeAppUser();

        $a1 = $this->createRequest($fixture, $userA->id);
        $a2 = $this->createRequest($fixture, $userA->id);
        $b1 = $this->createRequest($fixture, $userB->id);
        $b1->services()->attach($fixture['services'][0]->id);

        Sanctum::actingAs($userA, ['mobile']);

        $response = $this->getJson('/api/mobile/v2/requests')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $ids);
        $this->assertNotContains($b1->id, $ids);

        foreach ($response->json('data') as $row) {
            $this->assertSame((string) $userA->id, (string) $row['userId']);
        }
    }

    public function test_show_own_request_succeeds_but_another_users_request_is_404(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $userA = $this->makeAppUser();
        $userB = $this->makeAppUser();

        $owned = $this->createRequest($fixture, $userA->id);
        $foreign = $this->createRequest($fixture, $userB->id);

        Sanctum::actingAs($userA, ['mobile']);

        $this->getJson('/api/mobile/v2/requests/'.$owned->id)
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $owned->id);

        $this->getJson('/api/mobile/v2/requests/'.$foreign->id)
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors', null);
    }

    public function test_soft_deleted_request_is_inaccessible_and_not_listed(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $request = $this->createRequest($fixture, $user->id);
        $request->delete();

        Sanctum::actingAs($user, ['mobile']);

        // Soft-deleted requests must be inaccessible and return 404.
        $show = $this->getJson('/api/mobile/v2/requests/'.$request->id);
        $show->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->getJson('/api/mobile/v2/requests')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_missing_request_show_is_recorded_as_not_found_surface_defect(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);

        // Missing requests must return 404.
        $response = $this->getJson('/api/mobile/v2/requests/999999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_response_relationships_do_not_leak_other_users(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $userA = $this->makeAppUser();
        $userB = $this->makeAppUser();

        $request = $this->createRequest($fixture, $userA->id);
        $request->services()->attach($fixture['services'][0]->id);
        $this->createRequest($fixture, $userB->id);

        Sanctum::actingAs($userA, ['mobile']);

        $response = $this->getJson('/api/mobile/v2/requests')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $data = $response->json('data');
        $this->assertSame((string) $userA->id, (string) $data[0]['userId']);
        if (isset($data[0]['user']) && $data[0]['user'] !== null) {
            $this->assertSame((string) $userA->id, (string) $data[0]['user']['id']);
        }
    }
}
