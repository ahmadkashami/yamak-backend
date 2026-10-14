<?php

namespace Tests\Feature;

use App\Models\RequestIdempotencyKey;
use App\Models\ServiceRequest;
use App\Services\RequestCreationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.2C.1 — SQLite-level idempotency uniqueness behaviour.
 *
 * SCOPE / LIMITATION: these tests prove the *database-enforced* uniqueness of
 * `(app_user_id, key)` and the post-commit replay path on SQLite. They are NOT
 * proof of true concurrent behaviour under MySQL/InnoDB row locking — SQLite
 * serializes writes and has no InnoDB gap/next-key locks. Real race-condition
 * validation requires an isolated MySQL/InnoDB environment and is explicitly
 * out of scope here.
 */
class RequestIdempotencyConstraintTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    private function makeServiceRequestId(int $appUserId, int $driverId): int
    {
        return ServiceRequest::query()->create([
            'platform' => 'mobile',
            'app_user_id' => $appUserId,
            'driver_id' => $driverId,
            'status' => 'active',
        ])->id;
    }

    public function test_duplicate_owner_and_key_is_rejected_by_the_database(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $requestId = $this->makeServiceRequestId($user->id, $fixture['driver']->id);
        $key = (string) Str::uuid();

        RequestIdempotencyKey::query()->create([
            'app_user_id' => $user->id,
            'key' => $key,
            'request_fingerprint' => str_repeat('a', 64),
            'service_request_id' => $requestId,
            'expires_at' => now()->addDay(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        RequestIdempotencyKey::query()->create([
            'app_user_id' => $user->id,
            'key' => $key,
            'request_fingerprint' => str_repeat('b', 64),
            'service_request_id' => $requestId,
            'expires_at' => now()->addDay(),
        ]);
    }

    public function test_same_key_for_different_owners_is_allowed(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $userA = $this->makeAppUser();
        $userB = $this->makeAppUser();
        $requestA = $this->makeServiceRequestId($userA->id, $fixture['driver']->id);
        $requestB = $this->makeServiceRequestId($userB->id, $fixture['driver']->id);
        $key = (string) Str::uuid();

        RequestIdempotencyKey::query()->create([
            'app_user_id' => $userA->id,
            'key' => $key,
            'request_fingerprint' => str_repeat('a', 64),
            'service_request_id' => $requestA,
            'expires_at' => now()->addDay(),
        ]);

        RequestIdempotencyKey::query()->create([
            'app_user_id' => $userB->id,
            'key' => $key,
            'request_fingerprint' => str_repeat('c', 64),
            'service_request_id' => $requestB,
            'expires_at' => now()->addDay(),
        ]);

        $this->assertDatabaseCount('request_idempotency_keys', 2);
    }

    public function test_replay_after_a_committed_creation_is_served_without_writing(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = (string) Str::uuid();

        $service = app(RequestCreationService::class);
        $first = $service->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);
        $second = $service->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);

        $this->assertTrue($first['wasCreated']);
        $this->assertTrue($second['wasReplayed']);
        $this->assertSame($first['request']->id, $second['request']->id);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
    }
}
