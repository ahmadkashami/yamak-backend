<?php

namespace Tests\Feature;

use App\Exceptions\IdempotencyConflictException;
use App\Models\AppUser;
use App\Models\Driver;
use App\Models\RequestIdempotencyKey;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestStatusHistory;
use App\Models\Vehicle;
use App\Services\RequestCreationService;
use App\Support\Idempotency\RequestFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->expectException(IdempotencyConflictException::class);

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
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vehicleId', $exception->errors());
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    // ---------------------------------------------------------------------
    // B2.5C/C2 — tracked creation contract (createTracked()).
    //
    // Every test runs on the isolated SQLite :memory: connection configured by
    // phpunit.xml. No HTTP endpoint, persistent database or production data is
    // touched. These tests do NOT prove real MySQL/InnoDB concurrency.
    // ---------------------------------------------------------------------

    /**
     * @param  array{vehicle: Vehicle, driver: Driver}  $fixture
     * @param  list<int>  $serviceIds
     * @return array{request: ServiceRequest, wasCreated: bool, wasReplayed: bool}
     */
    private function tracked(
        AppUser $user,
        array $fixture,
        array $serviceIds,
        string $channel,
        string $key,
        float $latitude = 24.7136,
        float $longitude = 46.6753,
    ): array {
        return $this->service()->createTracked(
            $user,
            $fixture['vehicle']->id,
            $fixture['driver']->id,
            $serviceIds,
            $channel,
            $latitude,
            $longitude,
            $key,
        );
    }

    public function test_tracked_app_request_starts_pending(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $result = $this->tracked($user, $fixture, [$fixture['services'][0]->id], 'app', $this->uuid());

        $this->assertTrue($result['wasCreated']);
        $this->assertFalse($result['wasReplayed']);
        $this->assertSame('pending', $result['request']->fresh()->status);
        $this->assertSame('app', $result['request']->fresh()->channel);
    }

    public function test_tracked_whatsapp_request_starts_contact_initiated(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $result = $this->tracked($user, $fixture, [$fixture['services'][0]->id], 'whatsapp', $this->uuid());

        $this->assertTrue($result['wasCreated']);
        $this->assertSame('contact_initiated', $result['request']->fresh()->status);
        $this->assertSame('whatsapp', $result['request']->fresh()->channel);
    }

    public function test_tracked_phone_request_starts_contact_initiated(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $result = $this->tracked($user, $fixture, [$fixture['services'][0]->id], 'phone', $this->uuid());

        $this->assertTrue($result['wasCreated']);
        $this->assertSame('contact_initiated', $result['request']->fresh()->status);
        $this->assertSame('phone', $result['request']->fresh()->channel);
    }

    public function test_tracked_request_persists_vehicle_channel_and_coordinate_snapshot(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $result = $this->tracked(
            $user,
            $fixture,
            [$fixture['services'][0]->id],
            'whatsapp',
            $this->uuid(),
            24.7136,
            46.6753,
        );

        $fresh = $result['request']->fresh();

        $this->assertSame($fixture['vehicle']->id, (int) $fresh->vehicle_id);
        $this->assertSame($fixture['driver']->id, (int) $fresh->driver_id);
        $this->assertSame($user->id, (int) $fresh->app_user_id);
        $this->assertSame($fixture['company']->id, (int) $fresh->company_id);
        $this->assertSame($fixture['branch']->id, (int) $fresh->branch_id);
        $this->assertSame('mobile', $fresh->platform);
        $this->assertSame('whatsapp', $fresh->channel);
        $this->assertSame('contact_initiated', $fresh->status);
        $this->assertSame('24.7136000', $fresh->latitude);
        $this->assertSame('46.6753000', $fresh->longitude);
    }

    public function test_tracked_creation_writes_one_correct_initial_history_row(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $result = $this->tracked($user, $fixture, [$fixture['services'][0]->id], 'app', $this->uuid());

        $this->assertSame(1, ServiceRequestStatusHistory::query()->count());

        $history = ServiceRequestStatusHistory::query()->sole();

        $this->assertSame((int) $result['request']->id, (int) $history->service_request_id);
        $this->assertNull($history->from_status);
        $this->assertSame('pending', $history->to_status);
        $this->assertSame('app_user', $history->actor_type);
        $this->assertSame((int) $user->id, (int) $history->actor_id);
        $this->assertNull($history->reason_note);
        $this->assertSame(0, $history->reasons()->count());
        $this->assertSame(0, DB::table('service_request_status_history_reasons')->count());
    }

    public function test_tracked_replay_returns_the_same_request_without_extra_history(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = $this->uuid();

        $first = $this->tracked($user, $fixture, $ids, 'app', $key);

        // A replay must not re-check eligibility either.
        $fixture['vehicle']->update(['work_status' => 'offline']);

        $second = $this->tracked($user, $fixture, $ids, 'app', $key);

        $this->assertSame($first['request']->id, $second['request']->id);
        $this->assertTrue($second['wasReplayed']);
        $this->assertFalse($second['wasCreated']);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('request_service', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    public function test_tracked_replay_keeps_the_original_coordinate_snapshot(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = $this->uuid();

        $first = $this->tracked($user, $fixture, $ids, 'app', $key, 24.7136, 46.6753);
        $second = $this->tracked($user, $fixture, $ids, 'app', $key, 30.0444, 31.2357);

        $this->assertSame($first['request']->id, $second['request']->id);
        $this->assertTrue($second['wasReplayed']);

        $fresh = $second['request']->fresh();
        $this->assertSame('24.7136000', $fresh->latitude);
        $this->assertSame('46.6753000', $fresh->longitude);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    public function test_tracked_same_key_with_a_different_channel_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = $this->uuid();

        $this->tracked($user, $fixture, $ids, 'app', $key);

        try {
            $this->tracked($user, $fixture, $ids, 'whatsapp', $key);
            $this->fail('Expected an IdempotencyConflictException for a different channel.');
        } catch (IdempotencyConflictException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    public function test_tracked_same_key_with_different_services_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(2);
        $user = $this->makeAppUser();
        $key = $this->uuid();

        $this->tracked($user, $fixture, [$fixture['services'][0]->id], 'app', $key);

        try {
            $this->tracked($user, $fixture, [$fixture['services'][1]->id], 'app', $key);
            $this->fail('Expected an IdempotencyConflictException for different services.');
        } catch (IdempotencyConflictException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    public function test_existing_v1_claim_replays_for_the_app_channel(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = $this->uuid();

        $legacy = $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);

        $replay = $this->tracked($user, $fixture, $ids, 'app', $key);

        $this->assertSame($legacy['request']->id, $replay['request']->id);
        $this->assertTrue($replay['wasReplayed']);
        $this->assertFalse($replay['wasCreated']);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        // A legacy claim never gains a retroactive history row.
        $this->assertDatabaseCount('service_request_status_histories', 0);

        $claim = RequestIdempotencyKey::query()->sole();
        $this->assertSame(
            RequestFingerprint::make($user->id, $fixture['vehicle']->id, $fixture['driver']->id, $ids),
            $claim->request_fingerprint,
        );
    }

    public function test_existing_v1_claim_is_rejected_for_whatsapp_and_phone(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];

        foreach (['whatsapp', 'phone'] as $channel) {
            $key = $this->uuid();

            $this->service()->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key);

            try {
                $this->tracked($user, $fixture, $ids, $channel, $key);
                $this->fail("Expected a v1 claim to be rejected for the [{$channel}] channel.");
            } catch (IdempotencyConflictException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('service_requests', 2);
        $this->assertDatabaseCount('request_idempotency_keys', 2);
        $this->assertDatabaseCount('service_request_status_histories', 0);
    }

    public function test_v1_claim_linked_to_a_non_app_request_is_rejected_even_for_app_channel(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = $this->uuid();

        $request = ServiceRequest::query()->create([
            'platform' => 'mobile',
            'app_user_id' => $user->id,
            'vehicle_id' => $fixture['vehicle']->id,
            'driver_id' => $fixture['driver']->id,
            'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id,
            'channel' => 'whatsapp',
            'status' => 'contact_initiated',
        ]);

        RequestIdempotencyKey::query()->create([
            'app_user_id' => $user->id,
            'key' => $key,
            'request_fingerprint' => RequestFingerprint::make(
                $user->id,
                $fixture['vehicle']->id,
                $fixture['driver']->id,
                $ids,
            ),
            'service_request_id' => $request->id,
            'expires_at' => now()->addDay(),
        ]);

        try {
            $this->tracked($user, $fixture, $ids, 'app', $key);
            $this->fail('Expected a v1 claim linked to a non-app request to be rejected.');
        } catch (IdempotencyConflictException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
    }

    public function test_expired_but_present_tracked_claim_still_replays(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = $this->uuid();

        $first = $this->tracked($user, $fixture, $ids, 'app', $key);

        RequestIdempotencyKey::query()->update(['expires_at' => now()->subDay()]);

        $second = $this->tracked($user, $fixture, $ids, 'app', $key);

        $this->assertSame($first['request']->id, $second['request']->id);
        $this->assertTrue($second['wasReplayed']);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function invalidCoordinates(): array
    {
        return [
            'latitude above range' => [90.0001, 0.0],
            'latitude below range' => [-90.0001, 0.0],
            'longitude above range' => [0.0, 180.0001],
            'longitude below range' => [0.0, -180.0001],
            'both zero' => [0.0, 0.0],
            'latitude NaN' => [NAN, 0.0],
            'longitude NaN' => [0.0, NAN],
            'latitude infinity' => [INF, 0.0],
            'longitude negative infinity' => [0.0, -INF],
        ];
    }

    #[DataProvider('invalidCoordinates')]
    public function test_tracked_request_rejects_invalid_coordinates_before_writes(float $latitude, float $longitude): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        try {
            $this->tracked($user, $fixture, [$fixture['services'][0]->id], 'app', $this->uuid(), $latitude, $longitude);
            $this->fail('Expected invalid coordinates to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_service', 0);
        $this->assertDatabaseCount('service_request_status_histories', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_tracked_request_rejects_missing_or_non_numeric_coordinates(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];

        try {
            $this->service()->createTracked($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, 'app', null, 46.6753, $this->uuid());
            $this->fail('Expected a TypeError for a missing latitude value.');
        } catch (\TypeError $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        try {
            $this->service()->createTracked($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, 'app', 24.7136, 'not-a-number', $this->uuid());
            $this->fail('Expected a TypeError for a non-numeric longitude value.');
        } catch (\TypeError $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        try {
            $this->service()->createTracked($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, 'app', 24.7136);
            $this->fail('Expected an ArgumentCountError for a missing longitude argument.');
        } catch (\ArgumentCountError $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_tracked_request_rejects_an_unsaved_app_user_before_writes(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = new AppUser;

        try {
            $this->tracked($user, $fixture, [$fixture['services'][0]->id], 'app', $this->uuid());
            $this->fail('Expected an unsaved AppUser to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_tracked_request_accepts_an_individual_zero_coordinate(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];

        $zeroLatitude = $this->tracked($user, $fixture, $ids, 'app', $this->uuid(), 0.0, 46.6753);
        $zeroLongitude = $this->tracked($user, $fixture, $ids, 'app', $this->uuid(), 24.7136, 0.0);

        $this->assertSame('0.0000000', $zeroLatitude['request']->fresh()->latitude);
        $this->assertSame('46.6753000', $zeroLatitude['request']->fresh()->longitude);
        $this->assertSame('24.7136000', $zeroLongitude['request']->fresh()->latitude);
        $this->assertSame('0.0000000', $zeroLongitude['request']->fresh()->longitude);
        $this->assertDatabaseCount('service_requests', 2);
        $this->assertDatabaseCount('service_request_status_histories', 2);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidChannels(): array
    {
        return [
            'blank' => [''],
            'unknown' => ['email'],
            'sms' => ['sms'],
            'uppercase' => ['APP'],
            'mixed case' => ['WhatsApp'],
            'padded' => [' app '],
            'trailing space' => ['app '],
        ];
    }

    #[DataProvider('invalidChannels')]
    public function test_tracked_request_rejects_invalid_channels_before_writes(string $channel): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        try {
            $this->tracked($user, $fixture, [$fixture['services'][0]->id], $channel, $this->uuid());
            $this->fail('Expected an invalid channel to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_service', 0);
        $this->assertDatabaseCount('service_request_status_histories', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_tracked_creation_rolls_back_request_pivot_history_and_claim_on_failure(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $historyCountAtFailure = null;

        RequestIdempotencyKey::creating(function () use (&$historyCountAtFailure): void {
            // The claim is written last, so the initial history must already
            // exist at this point; the failure then has to roll it back too.
            $historyCountAtFailure = ServiceRequestStatusHistory::query()->count();

            throw new RuntimeException('forced claim failure');
        });

        try {
            $this->tracked($user, $fixture, [$fixture['services'][0]->id], 'app', $this->uuid());
            $this->fail('Expected the forced claim failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced claim failure', $exception->getMessage());
        }

        $this->assertSame(1, $historyCountAtFailure);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_service', 0);
        $this->assertDatabaseCount('service_request_status_histories', 0);
        $this->assertDatabaseCount('service_request_status_history_reasons', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_tracked_creation_still_enforces_existing_eligibility_rules(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $fixture['vehicle']->update(['work_status' => 'offline']);

        try {
            $this->tracked($user, $fixture, [$fixture['services'][0]->id], 'app', $this->uuid());
            $this->fail('Expected eligibility validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vehicleId', $exception->errors());
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('service_request_status_histories', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_legacy_create_behaviour_remains_unchanged(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];

        $result = $this->service()->create(
            $user,
            $fixture['vehicle']->id,
            $fixture['driver']->id,
            $ids,
            $this->uuid(),
        );

        $fresh = $result['request']->fresh();

        $this->assertTrue($result['wasCreated']);
        $this->assertFalse($result['wasReplayed']);
        $this->assertSame('active', $fresh->status);
        $this->assertSame('app', $fresh->channel);
        $this->assertNull($fresh->vehicle_id);
        $this->assertNull($fresh->latitude);
        $this->assertNull($fresh->longitude);
        $this->assertDatabaseCount('service_request_status_histories', 0);

        $claim = RequestIdempotencyKey::query()->sole();
        $this->assertSame(
            RequestFingerprint::make($user->id, $fixture['vehicle']->id, $fixture['driver']->id, $ids),
            $claim->request_fingerprint,
        );
    }

    public function test_tracked_creation_sends_no_notifications_or_queued_jobs(): void
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();

        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $this->tracked($user, $fixture, [$fixture['services'][0]->id], 'app', $this->uuid());

        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
