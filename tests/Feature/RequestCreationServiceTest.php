<?php

namespace Tests\Feature;

use App\Models\RequestIdempotencyKey;
use App\Models\ServiceRequest;
use App\Services\RequestCreationService;
use App\Support\Idempotency\RequestFingerprint;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.2C.1 — isolated verification of RequestCreationService.
 *
 * The service is exercised directly. No HTTP endpoint is invoked and no real
 * application server is contacted.
 */
class RequestCreationServiceTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RequestIdempotencyKey::flushEventListeners();
        parent::tearDown();
    }

    private function service(): RequestCreationService
    {
        return app(RequestCreationService::class);
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    public function test_first_request_creates_exactly_one_service_request(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $result = $this->service()->create(
            $user,
            $fixture['vehicle']->id,
            $fixture['driver']->id,
            [$fixture['services'][0]->id],
            $this->uuid(),
        );

        $this->assertTrue($result['wasCreated']);
        $this->assertFalse($result['wasReplayed']);
        $this->assertInstanceOf(ServiceRequest::class, $result['request']);
        $this->assertDatabaseCount('service_requests', 1);

        $request = $result['request']->fresh();
        $this->assertSame($user->id, (int) $request->app_user_id);
        $this->assertSame($fixture['driver']->id, (int) $request->driver_id);
        $this->assertSame($fixture['company']->id, (int) $request->company_id);
        $this->assertSame($fixture['branch']->id, (int) $request->branch_id);
        $this->assertSame('mobile', $request->platform);
        $this->assertSame('active', $request->status);
    }

    public function test_request_service_pivot_rows_are_written(): void
    {
        $fixture = $this->makeRequestFixture(2);
        $user = $this->makeAppUser();
        $ids = array_map(fn ($service) => $service->id, $fixture['services']);

        $result = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $this->uuid());

        $this->assertDatabaseCount('request_service', 2);
        $this->assertEqualsCanonicalizing($ids, $result['request']->fresh()->services->pluck('id')->all());
    }

    public function test_idempotency_key_row_is_written_with_fingerprint_and_expiry(): void
    {
        $fixture = $this->makeRequestFixture(2);
        $user = $this->makeAppUser();
        $ids = array_map(fn ($service) => $service->id, $fixture['services']);
        $key = $this->uuid();

        $result = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);

        $this->assertDatabaseCount('request_idempotency_keys', 1);

        $claim = RequestIdempotencyKey::query()->firstOrFail();
        $this->assertSame($user->id, (int) $claim->app_user_id);
        $this->assertSame(strtolower($key), $claim->key);
        $this->assertSame($result['request']->id, (int) $claim->service_request_id);
        $this->assertSame(
            RequestFingerprint::make($user->id, $fixture['vehicle']->id, $fixture['driver']->id, $ids),
            $claim->request_fingerprint,
        );
        $this->assertTrue($claim->expires_at->greaterThan(now()));
        $this->assertTrue($claim->expires_at->lessThanOrEqualTo(now()->addHours(RequestCreationService::RETENTION_HOURS)->addMinute()));
    }

    public function test_uppercase_key_is_stored_lowercased_and_replays_case_insensitively(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $key = strtoupper($this->uuid());

        $first = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, [$fixture['services'][0]->id], $key);
        $second = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, [$fixture['services'][0]->id], strtolower($key));

        $this->assertSame($first['request']->id, $second['request']->id);
        $this->assertTrue($second['wasReplayed']);
        $this->assertSame(strtolower($key), RequestIdempotencyKey::query()->firstOrFail()->key);
    }

    public function test_replay_returns_the_same_request_without_new_rows(): void
    {
        $fixture = $this->makeRequestFixture(2);
        $user = $this->makeAppUser();
        $ids = array_map(fn ($service) => $service->id, $fixture['services']);
        $key = $this->uuid();

        $first = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);
        $second = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);

        $this->assertSame($first['request']->id, $second['request']->id);
        $this->assertTrue($second['wasReplayed']);
        $this->assertFalse($second['wasCreated']);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('request_service', 2);
    }

    public function test_replay_does_not_sync_services_again(): void
    {
        $fixture = $this->makeRequestFixture(2);
        $user = $this->makeAppUser();
        $ids = array_map(fn ($service) => $service->id, $fixture['services']);
        $key = $this->uuid();

        $first = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);
        $pivotCountBefore = $first['request']->fresh()->services()->count();

        $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);

        $this->assertDatabaseCount('request_service', $pivotCountBefore);
    }

    public function test_same_key_with_different_payload_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(2);
        $user = $this->makeAppUser();
        $key = $this->uuid();

        $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, [$fixture['services'][0]->id], $key);

        $this->expectException(DomainException::class);

        $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, [$fixture['services'][1]->id], $key);
    }

    public function test_different_key_creates_a_second_request(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];

        $first = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $this->uuid());
        $second = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $this->uuid());

        $this->assertNotSame($first['request']->id, $second['request']->id);
        $this->assertTrue($second['wasCreated']);
        $this->assertDatabaseCount('service_requests', 2);
        $this->assertDatabaseCount('request_idempotency_keys', 2);
    }

    public function test_expired_but_present_key_still_replays_without_creating_a_new_request(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = $this->uuid();

        $first = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);

        RequestIdempotencyKey::query()->update(['expires_at' => now()->subDay()]);

        $second = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);

        $this->assertSame($first['request']->id, $second['request']->id);
        $this->assertTrue($second['wasReplayed']);
        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_foreign_user_cannot_replay_another_users_request(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $userA = $this->makeAppUser();
        $userB = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = $this->uuid();

        $first = $this->service()->create($userA, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);
        $second = $this->service()->create($userB, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);

        $this->assertNotSame($first['request']->id, $second['request']->id);
        $this->assertTrue($second['wasCreated']);
        $this->assertSame($userB->id, (int) $second['request']->fresh()->app_user_id);
        $this->assertDatabaseCount('service_requests', 2);
    }

    public function test_soft_deleted_request_does_not_silently_recreate(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = $this->uuid();

        $first = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);
        $first['request']->delete();

        try {
            $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);
            $this->fail('Expected a RuntimeException when the linked request is soft-deleted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no resolvable request', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
    }

    public function test_failed_creation_rolls_back_completely(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        RequestIdempotencyKey::creating(function (): void {
            throw new RuntimeException('forced write failure');
        });

        try {
            $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, [$fixture['services'][0]->id], $this->uuid());
            $this->fail('Expected the forced write failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced write failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_service', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_invalid_key_is_rejected_before_any_database_work(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        try {
            $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, [$fixture['services'][0]->id], 'not-a-uuid');
            $this->fail('Expected an InvalidArgumentException for a malformed key.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('UUID v4', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_empty_service_ids_are_rejected_before_any_database_work(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        try {
            $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, [], $this->uuid());
            $this->fail('Expected an InvalidArgumentException for empty service ids.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('At least one service', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_ineligible_creation_does_not_write_any_rows(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $fixture['vehicle']->update(['work_status' => 'offline']);

        try {
            $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, [$fixture['services'][0]->id], $this->uuid());
            $this->fail('Expected eligibility validation to fail.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('vehicleId', $exception->errors());
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }
}
