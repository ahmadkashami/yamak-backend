<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\RequestIdempotencyKey;
use App\Models\Service;
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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.5C/C3 — HTTP verification of the canonical tracked creation contract on
 * `POST /api/mobile/v1/requests`.
 *
 * Every request is served by the in-process test client against the isolated
 * SQLite :memory: database configured by phpunit.xml. No persistent database,
 * production data or running server is touched.
 */
class MobileRequestIdempotencyTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    private const LATITUDE = 29.3759;

    private const LONGITUDE = 47.9774;

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
            'services' => array_map(fn ($service) => $service->id, $fixture['services']),
            'channel' => 'app',
            'latitude' => self::LATITUDE,
            'longitude' => self::LONGITUDE,
        ], $overrides);
    }

    /**
     * @return list<string>
     */
    private function validationFields(TestResponse $response): array
    {
        return array_values(array_column($response->json('errors.details') ?? [], 'field'));
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    public function test_create_replay_and_conflict_preserve_one_request_and_its_services(): void
    {
        $fixture = $this->makeRequestFixture(2);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);
        $payload = $this->payload($fixture);
        $this->withHeader('Idempotency-Key', $this->uuid());

        $first = $this->postJson('/api/mobile/v1/requests', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.channel', 'app')
            ->assertJsonPath('data.driverId', (string) $fixture['driver']->id);
        $this->assertArrayNotHasKey('platform', $first->json('data'));
        $this->assertArrayNotHasKey('latitude', $first->json('data'));
        $this->assertArrayNotHasKey('longitude', $first->json('data'));

        $links = DB::table('request_service')->orderBy('service_id')->get()->toArray();
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
        $this->assertDatabaseCount('request_service', 2);

        $replay = $this->postJson('/api/mobile/v1/requests', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
        // Replay returns the same safe resource, not a re-shaped one.
        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);

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
        $this->assertDatabaseCount('service_request_status_histories', 1);
        $this->assertDatabaseCount('request_service', 2);
        $this->assertEquals($links, DB::table('request_service')->orderBy('service_id')->get()->toArray());
    }

    public function test_response_uses_only_the_approved_mobile_resource_shape(): void
    {
        $fixture = $this->makeRequestFixture(2, [], ['phone_num' => '965500000001']);
        $user = $this->makeAppUser([
            'name' => 'Private Customer',
            'email' => 'private.customer@example.com',
            'phone' => '965500000099',
            'fcm_token' => 'private-fcm-token',
            'notification_token' => 'private-notification-token',
            'latitude' => 29.1111111,
            'longitude' => 47.2222222,
        ]);
        Sanctum::actingAs($user, ['mobile']);

        $response = $this->withHeader('Idempotency-Key', $this->uuid())
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, ['channel' => 'app']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('messages.operation_success'));

        $request = ServiceRequest::query()->sole();
        $data = $response->json('data');

        // Standard envelope is unchanged: success/message/data/errors only.
        $this->assertSame(
            ['data', 'errors', 'message', 'success'],
            $this->sortedKeys($response->json()),
        );
        $this->assertNull($response->json('errors'));

        // Exact approved top-level whitelist and nothing else.
        $this->assertSame(
            ['_id', 'channel', 'createdAt', 'driver', 'driverId', 'id', 'services', 'status', 'vehicleId'],
            $this->sortedKeys($data),
        );

        $this->assertIsInt($data['id']);
        $this->assertSame($request->id, $data['id']);
        $this->assertIsString($data['_id']);
        $this->assertSame((string) $request->id, $data['_id']);
        $this->assertSame('app', $data['channel']);
        $this->assertSame('pending', $data['status']);
        $this->assertIsInt($data['vehicleId']);
        $this->assertSame($fixture['vehicle']->id, $data['vehicleId']);
        $this->assertIsString($data['driverId']);
        $this->assertSame((string) $fixture['driver']->id, $data['driverId']);

        // Minimal driver summary only.
        $this->assertSame(['id', 'name', 'phoneNum'], $this->sortedKeys($data['driver']));
        $this->assertSame($fixture['driver']->id, $data['driver']['id']);
        $this->assertSame('B2 Driver', $data['driver']['name']);
        $this->assertSame('965500000001', $data['driver']['phoneNum']);

        // Services contain only id and bilingual names.
        $this->assertCount(2, $data['services']);
        $servicesById = collect($data['services'])->keyBy('id');
        foreach ($fixture['services'] as $service) {
            $projected = $servicesById->get($service->id);
            $this->assertNotNull($projected, "Service [{$service->id}] is missing.");
            $this->assertSame(['id', 'name'], $this->sortedKeys($projected));
            $this->assertSame(['ar', 'en'], $this->sortedKeys($projected['name']));
            $this->assertSame($service->name_en, $projected['name']['en']);
            $this->assertSame($service->name_ar, $projected['name']['ar']);
        }

        // ISO-8601 timestamp identical to the persisted record.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $data['createdAt'],
        );
        $this->assertSame($request->created_at->toJSON(), $data['createdAt']);

        // Explicit absence checks for the sensitive/unapproved surface.
        foreach ([
            'appUser', 'user', 'userId', 'platform', 'email', 'password', 'phone',
            'fcmToken', 'fcm_token', 'notificationToken', 'notification_token',
            'latitude', 'longitude', 'location', 'photo', 'company', 'branch',
            'companyId', 'branchId', 'statusHistories', 'histories', 'history',
            'reason', 'reasonNote', 'admin',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, "Unapproved top-level key [{$forbidden}] leaked.");
        }

        foreach ([
            'email', 'password', 'driverLicense', 'driver_license', 'licenseExpiredAt',
            'company', 'branch', 'companyId', 'branchId', 'token', 'auth',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data['driver'], "Unapproved driver key [{$forbidden}] leaked.");
        }

        // Raw payload must not contain customer secrets or coordinate snapshots.
        $payload = $response->getContent();
        foreach ([
            'private-fcm-token',
            'private-notification-token',
            'private.customer@example.com',
            '965500000099',
            '29.3759',
            '47.9774',
            '29.1111111',
            '47.2222222',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }

        // Serialising the resource performs no extra writes.
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    /**
     * @return list<string>
     */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    public function test_all_three_channels_create_with_the_expected_status_and_actor(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);

        $expectedStatuses = [
            'app' => 'pending',
            'whatsapp' => 'contact_initiated',
            'phone' => 'contact_initiated',
        ];

        foreach ($expectedStatuses as $channel => $expectedStatus) {
            $this->withHeader('Idempotency-Key', $this->uuid())
                ->postJson('/api/mobile/v1/requests', $this->payload($fixture, ['channel' => $channel]))
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.status', $expectedStatus)
                ->assertJsonPath('data.channel', $channel);

            $request = ServiceRequest::query()->where('channel', $channel)->sole();
            $this->assertSame($expectedStatus, $request->status);
            $this->assertSame($user->id, (int) $request->app_user_id);

            $history = ServiceRequestStatusHistory::query()
                ->where('service_request_id', $request->id)
                ->sole();
            $this->assertNull($history->from_status);
            $this->assertSame($expectedStatus, $history->to_status);
            $this->assertSame('app_user', $history->actor_type);
            $this->assertSame($user->id, (int) $history->actor_id);
        }

        $this->assertDatabaseCount('service_requests', 3);
        $this->assertDatabaseCount('service_request_status_histories', 3);
        $this->assertDatabaseCount('request_idempotency_keys', 3);
    }

    public function test_new_request_persists_tracked_columns_pivot_and_v2_fingerprint(): void
    {
        $fixture = $this->makeRequestFixture(2);
        $user = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);
        $serviceIds = array_map(fn ($service) => $service->id, $fixture['services']);
        $key = $this->uuid();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, [
                'channel' => 'whatsapp',
                'services' => $serviceIds,
            ]))
            ->assertOk();

        $request = ServiceRequest::query()->sole();
        $this->assertSame($fixture['vehicle']->id, (int) $request->vehicle_id);
        $this->assertSame($fixture['driver']->id, (int) $request->driver_id);
        $this->assertSame($fixture['company']->id, (int) $request->company_id);
        $this->assertSame($fixture['branch']->id, (int) $request->branch_id);
        $this->assertSame($user->id, (int) $request->app_user_id);
        $this->assertSame('mobile', $request->platform);
        $this->assertSame('whatsapp', $request->channel);
        $this->assertSame('contact_initiated', $request->status);
        $this->assertSame('29.3759000', $request->latitude);
        $this->assertSame('47.9774000', $request->longitude);

        $this->assertEqualsCanonicalizing($serviceIds, $request->services()->pluck('services.id')->all());
        $this->assertDatabaseCount('request_service', 2);

        $claim = RequestIdempotencyKey::query()->sole();
        $this->assertSame($request->id, (int) $claim->service_request_id);
        $this->assertSame($user->id, (int) $claim->app_user_id);
        $this->assertSame(strtolower($key), $claim->key);
        $this->assertSame(
            RequestFingerprint::makeV2(
                $user->id,
                $fixture['vehicle']->id,
                $fixture['driver']->id,
                $serviceIds,
                'whatsapp',
            ),
            $claim->request_fingerprint,
        );
        $this->assertNotSame(
            RequestFingerprint::make($user->id, $fixture['vehicle']->id, $fixture['driver']->id, $serviceIds),
            $claim->request_fingerprint,
        );

        $history = ServiceRequestStatusHistory::query()->sole();
        $this->assertSame('contact_initiated', $history->to_status);
        $this->assertSame('app_user', $history->actor_type);
        $this->assertSame($user->id, (int) $history->actor_id);
        $this->assertNull($history->reason_note);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);

        $this->withHeader('Idempotency-Key', $this->uuid())
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
        $this->assertDatabaseCount('service_request_status_histories', 0);
    }

    public function test_wrong_token_ability_is_forbidden(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['dashboard']);

        $this->withHeader('Idempotency-Key', $this->uuid())
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_client_controlled_fields_are_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $victim = $this->makeAppUser();
        Sanctum::actingAs($user, ['mobile']);

        // Any supplied value, including null, empty string and empty array,
        // must be rejected: the canonical contract forbids the key entirely.
        $forbidden = [
            'userId' => [$victim->id, null, '', []],
            'status' => ['active', null, '', []],
            'platform' => ['web', null, '', []],
        ];

        foreach ($forbidden as $field => $values) {
            foreach ($values as $index => $value) {
                $response = $this->withHeader('Idempotency-Key', $this->uuid())
                    ->postJson('/api/mobile/v1/requests', $this->payload($fixture, [$field => $value]));

                $response->assertStatus(422)
                    ->assertJsonPath('success', false)
                    ->assertJsonPath('errors.code', 'VALIDATION_ERROR');

                $this->assertContains(
                    $field,
                    $this->validationFields($response),
                    "Field [{$field}] case [{$index}] was not reported.",
                );
                $this->assertNotContains(
                    'idempotencyKey',
                    $this->validationFields($response),
                    "Field [{$field}] case [{$index}] was mislabelled as an idempotency key error.",
                );
            }
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
        $this->assertDatabaseCount('service_request_status_histories', 0);
    }

    public function test_required_fields_are_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);
        $base = $this->payload($fixture);

        foreach (['vehicleId', 'driverId', 'services', 'channel', 'latitude', 'longitude'] as $field) {
            $payload = $base;
            unset($payload[$field]);

            $response = $this->withHeader('Idempotency-Key', $this->uuid())
                ->postJson('/api/mobile/v1/requests', $payload);

            $response->assertStatus(422)->assertJsonPath('errors.code', 'VALIDATION_ERROR');
            $this->assertContains($field, $this->validationFields($response), "Missing [{$field}] was not reported.");
        }

        $emptyServices = $this->withHeader('Idempotency-Key', $this->uuid())
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, ['services' => []]));
        $emptyServices->assertStatus(422);
        $this->assertContains('services', $this->validationFields($emptyServices));

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_coordinates_out_of_range_null_empty_or_non_numeric_are_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);

        $invalid = [
            'latitude above range' => ['latitude' => 90.0001],
            'latitude below range' => ['latitude' => -90.0001],
            'longitude above range' => ['longitude' => 180.0001],
            'longitude below range' => ['longitude' => -180.0001],
            'latitude null' => ['latitude' => null],
            'longitude null' => ['longitude' => null],
            'latitude empty' => ['latitude' => ''],
            'longitude empty' => ['longitude' => ''],
            'latitude non numeric' => ['latitude' => 'not-a-number'],
            'longitude non numeric' => ['longitude' => 'not-a-number'],
            'latitude array' => ['latitude' => [29.3]],
            'longitude boolean' => ['longitude' => true],
        ];

        foreach ($invalid as $case => $override) {
            $response = $this->withHeader('Idempotency-Key', $this->uuid())
                ->postJson('/api/mobile/v1/requests', $this->payload($fixture, $override));

            $response->assertStatus(422)->assertJsonPath('errors.code', 'VALIDATION_ERROR');

            $expectedField = array_key_first($override);
            $this->assertContains($expectedField, $this->validationFields($response), "Case [{$case}] was not reported on [{$expectedField}].");
            $this->assertNotContains('idempotencyKey', $this->validationFields($response), "Case [{$case}] was mislabelled as an idempotency key error.");
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
        $this->assertDatabaseCount('service_request_status_histories', 0);
    }

    public function test_non_finite_coordinates_are_rejected_without_internal_errors(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);

        // Numeric strings that overflow the float range to INF.
        foreach ([
            ['latitude' => '1e400'],
            ['latitude' => '-1e400'],
            ['longitude' => '1e400'],
            ['longitude' => '-1e400'],
        ] as $override) {
            $response = $this->withHeader('Idempotency-Key', $this->uuid())
                ->postJson('/api/mobile/v1/requests', $this->payload($fixture, $override));

            $response->assertStatus(422);
            $this->assertContains(array_key_first($override), $this->validationFields($response));
        }

        // A raw JSON numeric literal that json_decode turns into INF.
        $raw = str_replace(
            '"latitude":1',
            '"latitude":1e400',
            (string) json_encode($this->payload($fixture, ['latitude' => 1, 'longitude' => 1])),
        );

        $response = $this->call(
            'POST',
            '/api/mobile/v1/requests',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => $this->uuid(),
            ],
            $raw,
        );

        $response->assertStatus(422)->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertContains('latitude', $this->validationFields($response));

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_invalid_channel_is_reported_on_the_channel_field(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);

        // Case and unknown values are rejected by the enum rule. Padded values
        // are rejected by the raw JSON comparison: the global TrimStrings
        // middleware would otherwise silently normalise them to a valid value.
        foreach (['email', 'APP', 'WhatsApp', 'sms', 'app1', '', ' app ', 'whatsapp ', ' phone', "\tapp", "phone\n"] as $channel) {
            $response = $this->withHeader('Idempotency-Key', $this->uuid())
                ->postJson('/api/mobile/v1/requests', $this->payload($fixture, ['channel' => $channel]));

            $response->assertStatus(422);
            $fields = $this->validationFields($response);
            $this->assertContains('channel', $fields, 'Padded/invalid channel ['.json_encode($channel).'] was not reported.');
            $this->assertNotContains('idempotencyKey', $fields);
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
        $this->assertDatabaseCount('service_request_status_histories', 0);
    }

    public function test_both_zero_coordinates_are_rejected(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);

        $response = $this->withHeader('Idempotency-Key', $this->uuid())
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, [
                'latitude' => 0,
                'longitude' => 0,
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertContains('latitude', $this->validationFields($response));
        $this->assertNotContains('idempotencyKey', $this->validationFields($response));

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_individual_zero_coordinates_are_accepted(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);

        $this->withHeader('Idempotency-Key', $this->uuid())
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, [
                'latitude' => 0,
                'longitude' => 47.9774,
            ]))
            ->assertOk();

        $this->withHeader('Idempotency-Key', $this->uuid())
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, [
                'latitude' => 29.3759,
                'longitude' => 0,
            ]))
            ->assertOk();

        $requests = ServiceRequest::query()->orderBy('id')->get();
        $this->assertCount(2, $requests);
        $this->assertSame('0.0000000', $requests[0]->latitude);
        $this->assertSame('47.9774000', $requests[0]->longitude);
        $this->assertSame('29.3759000', $requests[1]->latitude);
        $this->assertSame('0.0000000', $requests[1]->longitude);
        $this->assertDatabaseCount('service_request_status_histories', 2);
    }

    public function test_missing_or_malformed_idempotency_key_is_reported_on_its_own_field(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);

        $missing = $this->postJson('/api/mobile/v1/requests', $this->payload($fixture));
        $missing->assertStatus(422);
        $this->assertContains('idempotencyKey', $this->validationFields($missing));

        $malformed = $this->withHeader('Idempotency-Key', 'not-a-uuid')
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture));
        $malformed->assertStatus(422);
        $this->assertContains('idempotencyKey', $this->validationFields($malformed));

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
    }

    public function test_ineligible_vehicle_is_reported_on_the_vehicle_field(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $fixture['vehicle']->update(['work_status' => 'offline']);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);

        $response = $this->withHeader('Idempotency-Key', $this->uuid())
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture));

        $response->assertStatus(422)->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertContains('vehicleId', $this->validationFields($response));
        $this->assertNotContains('idempotencyKey', $this->validationFields($response));

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('request_idempotency_keys', 0);
        $this->assertDatabaseCount('service_request_status_histories', 0);
    }

    public function test_same_key_with_changed_gps_replays_the_original_snapshot(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);
        $key = $this->uuid();

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertOk();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, [
                'latitude' => 30.0444,
                'longitude' => 31.2357,
            ]))
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $request = ServiceRequest::query()->sole();
        $this->assertSame('29.3759000', $request->latitude);
        $this->assertSame('47.9774000', $request->longitude);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    public function test_replay_does_not_recheck_current_eligibility(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);
        $key = $this->uuid();

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertOk();

        $fixture['vehicle']->update(['work_status' => 'offline']);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    public function test_same_key_with_a_different_channel_conflicts(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);
        $key = $this->uuid();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, ['channel' => 'app']))
            ->assertOk();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, ['channel' => 'whatsapp']))
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => 'The Idempotency-Key was already used with a different payload.',
                'data' => null,
                'errors' => null,
            ]);

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    public function test_same_key_with_different_services_conflicts(): void
    {
        $fixture = $this->makeRequestFixture(2);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);
        $key = $this->uuid();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, [
                'services' => [$fixture['services'][0]->id],
            ]))
            ->assertOk();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture, [
                'services' => [$fixture['services'][1]->id],
            ]))
            ->assertStatus(409);

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        $this->assertDatabaseCount('request_service', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    public function test_expired_but_present_claim_still_replays(): void
    {
        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);
        $key = $this->uuid();

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertOk();

        RequestIdempotencyKey::query()->update(['expires_at' => now()->subDay()]);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('service_request_status_histories', 1);
    }

    public function test_existing_v1_claim_replays_for_the_app_channel_without_new_history(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $serviceIds = [$fixture['services'][0]->id];
        $key = $this->uuid();

        $legacy = app(RequestCreationService::class)->create(
            $user,
            $fixture['vehicle']->id,
            $fixture['driver']->id,
            $serviceIds,
            $key,
        );

        Sanctum::actingAs($user, ['mobile']);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertOk()
            ->assertJsonPath('data.id', $legacy['request']->id);

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('request_idempotency_keys', 1);
        // A legacy claim never gains a retroactive history row.
        $this->assertDatabaseCount('service_request_status_histories', 0);

        $claim = RequestIdempotencyKey::query()->sole();
        $this->assertSame(
            RequestFingerprint::make($user->id, $fixture['vehicle']->id, $fixture['driver']->id, $serviceIds),
            $claim->request_fingerprint,
        );
    }

    public function test_existing_v1_claim_conflicts_for_whatsapp_and_phone(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $serviceIds = [$fixture['services'][0]->id];
        $service = app(RequestCreationService::class);

        foreach (['whatsapp', 'phone'] as $channel) {
            $key = $this->uuid();

            $service->create(
                $user,
                $fixture['vehicle']->id,
                $fixture['driver']->id,
                $serviceIds,
                $key,
            );

            Sanctum::actingAs($user, ['mobile']);

            $this->withHeader('Idempotency-Key', $key)
                ->postJson('/api/mobile/v1/requests', $this->payload($fixture, ['channel' => $channel]))
                ->assertStatus(409);
        }

        $this->assertDatabaseCount('service_requests', 2);
        $this->assertDatabaseCount('request_idempotency_keys', 2);
        $this->assertDatabaseCount('service_request_status_histories', 0);
    }

    public function test_creation_and_replay_send_no_notifications_or_queued_jobs(): void
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();

        $fixture = $this->makeRequestFixture(1);
        Sanctum::actingAs($this->makeAppUser(), ['mobile']);
        $key = $this->uuid();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertOk();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/mobile/v1/requests', $this->payload($fixture))
            ->assertOk();

        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
