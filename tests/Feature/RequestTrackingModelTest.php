<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\RequestReason;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestStatusHistory;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.5B change step 2 — isolated Eloquent model verification.
 *
 * These tests exercise only the model relations and casts through the SQLite
 * :memory: connection configured by phpunit.xml. They touch no real database
 * and prove no lifecycle transition or validation behaviour.
 */
class RequestTrackingModelTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{request: ServiceRequest, fixture: array<string, mixed>, user: AppUser}
     */
    private function makeTrackedRequest(array $overrides = []): array
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $request = ServiceRequest::query()->create(array_merge([
            'platform' => 'mobile',
            'app_user_id' => $user->id,
            'driver_id' => $fixture['driver']->id,
            'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id,
            'vehicle_id' => $fixture['vehicle']->id,
        ], $overrides));

        return compact('request', 'fixture', 'user');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeHistory(
        ?ServiceRequest $request = null,
        array $overrides = [],
    ): ServiceRequestStatusHistory {
        $request ??= $this->makeTrackedRequest()['request'];

        return ServiceRequestStatusHistory::query()->create(array_merge([
            'service_request_id' => $request->id,
            'from_status' => null,
            'to_status' => 'pending',
            'actor_type' => 'system',
            'actor_id' => null,
            'reason_note' => null,
        ], $overrides));
    }

    /**
     * @param  list<string>  $codes
     * @return list<int>
     */
    private function reasonIds(array $codes): array
    {
        $ids = RequestReason::query()
            ->whereIn('code', $codes)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertCount(count($codes), $ids);

        return $ids;
    }

    public function test_service_request_belongs_to_vehicle(): void
    {
        ['request' => $request, 'fixture' => $fixture] = $this->makeTrackedRequest();

        $this->assertInstanceOf(Vehicle::class, $request->vehicle);
        $this->assertSame($fixture['vehicle']->id, $request->vehicle->id);
        $this->assertTrue($request->vehicle->is($fixture['vehicle']));
    }

    public function test_service_request_has_many_status_histories(): void
    {
        $request = $this->makeTrackedRequest()['request'];

        $first = $this->makeHistory($request);
        $second = $this->makeHistory($request, [
            'from_status' => 'pending',
            'to_status' => 'accepted',
        ]);

        $histories = $request->statusHistories()->get();

        $this->assertCount(2, $histories);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $histories->pluck('id')->all(),
        );
    }

    public function test_status_history_belongs_to_service_request(): void
    {
        ['request' => $request] = $this->makeTrackedRequest();
        $history = $this->makeHistory($request);

        $this->assertInstanceOf(ServiceRequest::class, $history->serviceRequest);
        $this->assertTrue($history->serviceRequest->is($request));
    }

    public function test_status_history_belongs_to_many_reasons(): void
    {
        $history = $this->makeHistory();
        [$reasonId] = $this->reasonIds(['too_far']);

        $history->reasons()->attach($reasonId);

        $reason = $history->fresh()->reasons->sole();

        $this->assertSame('too_far', $reason->code);
        $this->assertSame($history->id, (int) $reason->pivot->status_history_id);
        $this->assertSame($reasonId, (int) $reason->pivot->reason_id);
    }

    public function test_request_reason_inverse_relationship_works(): void
    {
        $history = $this->makeHistory();
        $reason = RequestReason::query()->where('code', 'materials_unavailable')->firstOrFail();

        $history->reasons()->attach($reason->id);

        $relatedHistoryIds = $reason->fresh()->statusHistories->pluck('id')->all();

        $this->assertCount(1, $relatedHistoryIds);
        $this->assertSame($history->id, $relatedHistoryIds[0]);
    }

    public function test_two_approved_reasons_can_be_loaded_from_one_status_history(): void
    {
        $history = $this->makeHistory();
        $reasonIds = $this->reasonIds(['too_far', 'materials_unavailable']);

        $history->reasons()->attach($reasonIds);

        $loadedReasons = $history->fresh()->reasons;

        $this->assertCount(2, $loadedReasons);
        $this->assertEqualsCanonicalizing(
            ['too_far', 'materials_unavailable'],
            $loadedReasons->pluck('code')->all(),
        );
        $this->assertEqualsCanonicalizing(
            $reasonIds,
            $loadedReasons->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_latitude_and_longitude_decimal_casts_retain_seven_decimal_places(): void
    {
        $request = $this->makeTrackedRequest([
            'latitude' => '24.7000000',
            'longitude' => '46.6752957',
        ])['request'];

        $fresh = $request->fresh();

        $this->assertIsString($fresh->latitude);
        $this->assertIsString($fresh->longitude);
        $this->assertSame('24.7000000', $fresh->latitude);
        $this->assertSame('46.6752957', $fresh->longitude);
    }

    public function test_null_coordinates_remain_null(): void
    {
        $request = $this->makeTrackedRequest([
            'latitude' => null,
            'longitude' => null,
        ])['request'];

        $fresh = $request->fresh();

        $this->assertNull($fresh->latitude);
        $this->assertNull($fresh->longitude);
        $this->assertArrayHasKey('latitude', $fresh->getAttributes());
        $this->assertNull($fresh->getAttributes()['latitude']);
        $this->assertArrayHasKey('longitude', $fresh->getAttributes());
        $this->assertNull($fresh->getAttributes()['longitude']);
    }

    public function test_status_history_created_at_works_without_an_updated_at_column(): void
    {
        $history = $this->makeHistory();

        $this->assertFalse(Schema::hasColumn('service_request_status_histories', 'updated_at'));
        $this->assertNull($history->getUpdatedAtColumn());
        $this->assertArrayNotHasKey('updated_at', $history->getAttributes());
        $this->assertInstanceOf(Carbon::class, $history->created_at);
        $this->assertNotNull($history->created_at);
    }

    public function test_existing_service_request_relationships_continue_working(): void
    {
        ['request' => $request, 'fixture' => $fixture, 'user' => $user] = $this->makeTrackedRequest();

        $request->services()->attach($fixture['services'][0]->id);

        $fresh = $request->fresh();

        $this->assertTrue($fresh->appUser->is($user));
        $this->assertTrue($fresh->driver->is($fixture['driver']));
        $this->assertTrue($fresh->company->is($fixture['company']));
        $this->assertTrue($fresh->branch->is($fixture['branch']));
        $this->assertCount(1, $fresh->services);
        $this->assertTrue($fresh->services->first()->is($fixture['services'][0]));
    }
}
