<?php

namespace Tests\Feature;

use App\Models\ServiceRequest;
use App\Models\ServiceRequestStatusHistory;
use App\Services\RequestStatusHistoryRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.5B step 4B-1 — initial status-history recorder verification.
 *
 * Every test runs on the isolated SQLite :memory: connection configured by
 * phpunit.xml. No persistent database, seeder or production data is used.
 */
class RequestStatusHistoryRecorderTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    private RequestStatusHistoryRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = new RequestStatusHistoryRecorder;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeRequest(array $overrides = []): ServiceRequest
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        return ServiceRequest::query()->create(array_merge([
            'platform' => 'mobile',
            'app_user_id' => $user->id,
            'driver_id' => $fixture['driver']->id,
            'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id,
            'vehicle_id' => $fixture['vehicle']->id,
            'channel' => 'app',
            'status' => 'pending',
        ], $overrides));
    }

    private function historyCount(): int
    {
        return ServiceRequestStatusHistory::query()->count();
    }

    private function assertRejected(
        ServiceRequest $request,
        string $actorType = 'system',
        ?int $actorId = null,
    ): void {
        try {
            $this->recorder->recordInitial($request, $actorType, $actorId);
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());

            return;
        }

        $this->fail('Expected the initial history recorder to reject the request.');
    }

    public function test_pending_request_records_one_initial_entry(): void
    {
        $request = $this->makeRequest(['status' => 'pending']);

        $history = $this->recorder->recordInitial($request, 'system');

        $this->assertSame((int) $request->id, (int) $history->service_request_id);
        $this->assertNull($history->from_status);
        $this->assertSame('pending', $history->to_status);
        $this->assertSame('system', $history->actor_type);
        $this->assertNull($history->actor_id);
        $this->assertNull($history->reason_note);
        $this->assertInstanceOf(Carbon::class, $history->created_at);
        $this->assertNotNull($history->created_at);
        $this->assertSame(1, $this->historyCount());
    }

    public function test_contact_initiated_request_records_one_initial_entry(): void
    {
        $request = $this->makeRequest([
            'channel' => 'whatsapp',
            'status' => 'contact_initiated',
        ]);

        $history = $this->recorder->recordInitial($request, 'system', 7);

        $this->assertSame((int) $request->id, (int) $history->service_request_id);
        $this->assertNull($history->from_status);
        $this->assertSame('contact_initiated', $history->to_status);
        $this->assertSame('system', $history->actor_type);
        $this->assertSame(7, (int) $history->actor_id);
        $this->assertNull($history->reason_note);
        $this->assertSame(1, $this->historyCount());
    }

    public function test_legacy_active_status_is_rejected_without_history(): void
    {
        $request = $this->makeRequest(['status' => 'active']);

        $this->assertRejected($request);

        $this->assertSame(0, $this->historyCount());
    }

    public function test_non_initial_and_unknown_statuses_are_rejected(): void
    {
        foreach (['accepted', 'completed', 'not_completed', 'cancelled', 'processing', 'ACCEPTED'] as $status) {
            $request = $this->makeRequest(['status' => $status]);

            $this->assertRejected($request);
            $this->assertSame(0, $this->historyCount(), "Status [{$status}] must not create history.");
        }

        // A null in-memory status cannot match a persisted status and must be
        // rejected rather than silently repaired.
        $request = $this->makeRequest(['status' => 'pending']);
        $request->status = null;

        $this->assertRejected($request);
        $this->assertSame(0, $this->historyCount());
    }

    public function test_all_approved_actor_types_are_accepted(): void
    {
        foreach (['system', 'app_user', 'admin', 'provider'] as $actorType) {
            $request = $this->makeRequest();

            $history = $this->recorder->recordInitial($request, $actorType, 42);

            $this->assertSame($actorType, $history->actor_type);
            $this->assertSame(42, (int) $history->actor_id);
        }

        $this->assertSame(4, $this->historyCount());
    }

    public function test_blank_and_unknown_actor_types_are_rejected(): void
    {
        foreach (['', '   ', 'robot', 'System'] as $actorType) {
            $request = $this->makeRequest();

            $this->assertRejected($request, $actorType);
            $this->assertSame(0, $this->historyCount(), "Actor type [{$actorType}] must be rejected.");
        }
    }

    public function test_second_initial_entry_is_rejected_and_original_row_is_unchanged(): void
    {
        $request = $this->makeRequest();

        $first = $this->recorder->recordInitial($request, 'system');
        $before = $first->fresh()->toArray();

        $this->assertRejected($request);

        $this->assertSame(1, $this->historyCount());
        $this->assertSame($before, $first->fresh()->toArray());
    }

    public function test_unsaved_request_is_rejected(): void
    {
        $request = new ServiceRequest([
            'platform' => 'mobile',
            'status' => 'pending',
        ]);

        $this->assertFalse($request->exists);

        $this->assertRejected($request);

        $this->assertSame(0, $this->historyCount());
    }

    public function test_missing_and_soft_deleted_requests_are_rejected(): void
    {
        $hardDeleted = $this->makeRequest();
        DB::table('service_requests')->where('id', $hardDeleted->id)->delete();

        $this->assertRejected($hardDeleted);
        $this->assertSame(0, $this->historyCount());

        $softDeleted = $this->makeRequest();
        $softDeleted->delete();

        $this->assertRejected($softDeleted);
        $this->assertSame(0, $this->historyCount());
    }

    public function test_recorder_changes_no_request_attributes_and_attaches_no_reasons(): void
    {
        $request = $this->makeRequest([
            'channel' => 'whatsapp',
            'status' => 'contact_initiated',
            'latitude' => '24.7000000',
            'longitude' => '46.6752957',
        ]);

        $columns = ['status', 'channel', 'vehicle_id', 'latitude', 'longitude'];
        $before = $request->fresh()->only($columns);

        $history = $this->recorder->recordInitial($request, 'system');

        $this->assertSame($before, $request->fresh()->only($columns));
        $this->assertSame(0, $history->reasons()->count());
        $this->assertSame(0, DB::table('service_request_status_history_reasons')->count());
    }

    public function test_history_insert_rolls_back_with_the_caller_transaction(): void
    {
        $request = $this->makeRequest();

        try {
            DB::transaction(function () use ($request): void {
                $this->recorder->recordInitial($request, 'system');

                $this->assertSame(1, $this->historyCount());

                throw new RuntimeException('deliberate rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('deliberate rollback', $exception->getMessage());
        }

        $this->assertSame(0, $this->historyCount());
    }

    public function test_two_requests_each_receive_their_own_initial_entry(): void
    {
        $pending = $this->makeRequest(['status' => 'pending']);
        $contactInitiated = $this->makeRequest([
            'channel' => 'whatsapp',
            'status' => 'contact_initiated',
        ]);

        $pendingHistory = $this->recorder->recordInitial($pending, 'system');
        $contactHistory = $this->recorder->recordInitial($contactInitiated, 'system');

        $this->assertSame(2, $this->historyCount());
        $this->assertNotSame((int) $pendingHistory->id, (int) $contactHistory->id);
        $this->assertSame((int) $pending->id, (int) $pendingHistory->service_request_id);
        $this->assertSame((int) $contactInitiated->id, (int) $contactHistory->service_request_id);
        $this->assertSame('pending', $pendingHistory->to_status);
        $this->assertSame('contact_initiated', $contactHistory->to_status);
    }

    public function test_in_memory_status_change_without_persisting_is_rejected(): void
    {
        $request = $this->makeRequest(['status' => 'pending']);
        $request->status = 'contact_initiated';

        $this->assertRejected($request);

        $this->assertSame(
            'pending',
            DB::table('service_requests')->where('id', $request->id)->value('status'),
        );
        $this->assertSame(0, $this->historyCount());
    }

    public function test_stale_model_status_is_rejected(): void
    {
        $request = $this->makeRequest(['status' => 'pending']);

        DB::table('service_requests')
            ->where('id', $request->id)
            ->update(['status' => 'accepted']);

        $this->assertSame('pending', $request->status);

        $this->assertRejected($request);

        $this->assertSame(
            'accepted',
            DB::table('service_requests')->where('id', $request->id)->value('status'),
        );
        $this->assertSame(0, $this->historyCount());
    }
}
