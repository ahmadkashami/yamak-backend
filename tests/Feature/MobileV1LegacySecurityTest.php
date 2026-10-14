<?php

namespace Tests\Feature;

use App\Mail\UserRequestOrderMail;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.2C.2 — HTTP security verification for the retained legacy v1 request path.
 *
 * v1 POST is preserved for the legacy mobile client but is now authenticated
 * and ownership-guarded. The public v1 index/show/update/destroy methods are
 * gone. All requests are served by the in-process test client; nothing is sent
 * to a running server.
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

    public function test_v1_store_requires_authentication(): void
    {
        $fixture = $this->makeRequestFixture(1);

        $this->postJson('/api/mobile/v1/requests', [
            'driverId' => $fixture['driver']->id,
            'userId' => 1,
            'services' => [$fixture['services'][0]->id],
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_v1_store_accepts_authenticated_owner_under_legacy_contract(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);

        $this->postJson('/api/mobile/v1/requests', [
            'driverId' => $fixture['driver']->id,
            'userId' => $user->id,
            'services' => [$fixture['services'][0]->id],
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.driverId', (string) $fixture['driver']->id)
            ->assertJsonPath('data.userId', (string) $user->id);

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertSame($user->id, (int) ServiceRequest::query()->firstOrFail()->app_user_id);

        Mail::assertSent(UserRequestOrderMail::class, 1);
        Mail::assertSent(
            UserRequestOrderMail::class,
            fn (UserRequestOrderMail $mail): bool => $mail->hasTo($fixture['driver']->email),
        );
    }

    public function test_v1_store_rejects_spoofed_owner_and_writes_nothing(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $victim = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);

        $this->postJson('/api/mobile/v1/requests', [
            'driverId' => $fixture['driver']->id,
            'userId' => $victim->id,
            'services' => [$fixture['services'][0]->id],
        ])
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('service_requests', 0);
        Mail::assertNothingSent();
    }

    public function test_v1_index_is_not_exposed(): void
    {
        // `requests` matches the retained POST route, so a GET is a
        // method-not-allowed (observed status), never a read.
        $this->getJson('/api/mobile/v1/requests')
            ->assertStatus(405);
    }

    public function test_v1_show_is_not_exposed(): void
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
            'status' => 'active',
        ]);

        $this->getJson('/api/mobile/v1/requests/'.$request->id)
            ->assertStatus(404);
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
            'status' => 'active',
        ]);

        foreach (['put', 'patch', 'delete'] as $verb) {
            $this->json($verb, '/api/mobile/v1/requests/'.$request->id, [])
                ->assertStatus(404);
        }

        $this->assertDatabaseCount('service_requests', 1);
    }
}
