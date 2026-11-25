<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.5B change step 1 — isolated schema verification.
 *
 * These tests exercise only the new migration structures through the SQLite
 * :memory: connection configured by phpunit.xml. They touch no real database
 * and prove schema/relational behaviour, not the deferred lifecycle service.
 */
class RequestTrackingSchemaTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    /** @var array<string, string> */
    private const APPROVED_REASONS = [
        'too_far' => 'المكان بعيد',
        'service_cannot_be_provided' => 'لا أستطيع تقديم الخدمة',
        'materials_unavailable' => 'المواد غير متوفرة',
        'customer_unreachable' => 'تعذر التواصل مع العميل',
        'customer_cancelled' => 'العميل ألغى الطلب',
        'provider_unavailable' => 'مقدم الخدمة غير متاح',
        'service_unavailable' => 'الخدمة المطلوبة غير متوفرة',
        'location_inaccessible' => 'موقع العميل غير قابل للوصول',
        'price_not_agreed' => 'لم يتم الاتفاق على السعر',
        'other' => 'سبب آخر',
    ];

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function makeRequestRow(array $overrides = []): array
    {
        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $id = DB::table('service_requests')->insertGetId(array_merge([
            'platform' => 'mobile',
            'app_user_id' => $user->id,
            'driver_id' => $fixture['driver']->id,
            'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return [(int) $id, $fixture];
    }

    private function makeHistory(int $serviceRequestId): int
    {
        return (int) DB::table('service_request_status_histories')->insertGetId([
            'service_request_id' => $serviceRequestId,
            'to_status' => 'pending',
            'actor_type' => 'system',
        ]);
    }

    public function test_service_requests_has_additive_tracking_columns(): void
    {
        foreach (['vehicle_id', 'channel', 'latitude', 'longitude'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('service_requests', $column),
                "Missing service_requests.{$column}"
            );
        }
    }

    public function test_new_rows_default_to_pending_and_app_channel_with_nullable_history_facts(): void
    {
        [$id] = $this->makeRequestRow();

        $row = DB::table('service_requests')->find($id);

        $this->assertSame('pending', $row->status);
        $this->assertSame('app', $row->channel);
        $this->assertNull($row->vehicle_id);
        $this->assertNull($row->latitude);
        $this->assertNull($row->longitude);
    }

    public function test_explicit_active_writes_remain_allowed_until_the_later_compatibility_slice(): void
    {
        [$id] = $this->makeRequestRow(['status' => 'active']);

        $this->assertSame('active', DB::table('service_requests')->where('id', $id)->value('status'));
    }

    public function test_status_index_survives_the_default_change(): void
    {
        $hasStatusIndex = collect(Schema::getIndexes('service_requests'))
            ->contains(fn (array $index): bool => $index['columns'] === ['status']);

        $this->assertTrue($hasStatusIndex, 'service_requests.status index was lost.');
    }

    public function test_vehicle_foreign_key_nulls_the_request_reference_on_hard_delete(): void
    {
        [$id, $fixture] = $this->makeRequestRow();

        DB::table('service_requests')->where('id', $id)->update([
            'vehicle_id' => $fixture['vehicle']->id,
        ]);

        DB::table('vehicles')->where('id', $fixture['vehicle']->id)->delete();

        $this->assertNull(DB::table('service_requests')->where('id', $id)->value('vehicle_id'));
    }

    public function test_status_history_shape_and_initial_nullable_values(): void
    {
        foreach ([
            'id',
            'service_request_id',
            'from_status',
            'to_status',
            'actor_type',
            'actor_id',
            'reason_note',
            'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('service_request_status_histories', $column),
                "Missing service_request_status_histories.{$column}"
            );
        }

        [$requestId] = $this->makeRequestRow();
        $historyId = $this->makeHistory($requestId);

        $history = DB::table('service_request_status_histories')->find($historyId);

        $this->assertNull($history->from_status);
        $this->assertNull($history->actor_id);
        $this->assertNull($history->reason_note);
        $this->assertNotNull($history->created_at);
    }

    public function test_actual_migration_backfills_historical_rows_without_touching_other_data(): void
    {
        $connectionName = 'b2_5b_backfill_sqlite';
        $originalDefault = DB::getDefaultConnection();

        config()->set("database.connections.{$connectionName}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge($connectionName);

        $stepOneMigrations = [
            '2026_11_04_000001_add_request_tracking_columns_to_service_requests.php',
            '2026_11_04_000002_create_request_lifecycle_tables.php',
        ];

        $priorMigrations = collect(glob(database_path('migrations/*_*.php')))
            ->reject(fn (string $path): bool => in_array(basename($path), $stepOneMigrations, true))
            ->values()
            ->all();

        $this->assertNotEmpty($priorMigrations, 'No pre-B2.5B migrations were found.');

        try {
            $this->artisan('migrate', [
                '--database' => $connectionName,
                '--path' => $priorMigrations,
                '--realpath' => true,
                '--force' => true,
            ])->assertSuccessful();

            DB::setDefaultConnection($connectionName);

            $fixture = $this->makeRequestFixture(1);
            $user = $this->makeAppUser();
            $now = now();

            $historicalRows = [
                'active' => ['status' => 'active', 'deleted_at' => null],
                'soft_deleted_active' => ['status' => 'active', 'deleted_at' => $now],
                'pending' => ['status' => 'pending', 'deleted_at' => null],
                'completed' => ['status' => 'completed', 'deleted_at' => null],
            ];

            $ids = [];
            foreach ($historicalRows as $key => $row) {
                $ids[$key] = (int) DB::table('service_requests')->insertGetId([
                    'platform' => 'mobile',
                    'app_user_id' => $user->id,
                    'driver_id' => $fixture['driver']->id,
                    'company_id' => $fixture['company']->id,
                    'branch_id' => $fixture['branch']->id,
                    'status' => $row['status'],
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => $row['deleted_at'],
                ]);
            }

            $countBefore = DB::table('service_requests')->count();
            $idsBefore = DB::table('service_requests')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $migration = require database_path(
                'migrations/2026_11_04_000001_add_request_tracking_columns_to_service_requests.php'
            );
            $migration->up();

            $this->assertSame($countBefore, DB::table('service_requests')->count());
            $this->assertSame(
                $idsBefore,
                DB::table('service_requests')
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all()
            );

            $statuses = DB::table('service_requests')
                ->pluck('status', 'id')
                ->mapWithKeys(fn (string $status, $id): array => [(int) $id => $status])
                ->all();

            $this->assertSame('pending', $statuses[$ids['active']]);
            $this->assertSame('pending', $statuses[$ids['soft_deleted_active']]);
            $this->assertSame('pending', $statuses[$ids['pending']]);
            $this->assertSame('completed', $statuses[$ids['completed']]);

            foreach ($ids as $key => $id) {
                $row = DB::table('service_requests')->find($id);

                $this->assertSame('app', $row->channel, "Unexpected channel for {$key}.");
                $this->assertNull($row->vehicle_id, "Unexpected vehicle_id for {$key}.");
                $this->assertNull($row->latitude, "Unexpected latitude for {$key}.");
                $this->assertNull($row->longitude, "Unexpected longitude for {$key}.");
            }

            $softDeleted = DB::table('service_requests')->find($ids['soft_deleted_active']);

            $this->assertNotNull($softDeleted->deleted_at);
            $this->assertSame('pending', $softDeleted->status);
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge($connectionName);
        }
    }

    public function test_reason_catalog_contains_exactly_the_approved_codes_and_labels(): void
    {
        $this->assertSame(count(self::APPROVED_REASONS), DB::table('request_reasons')->count());

        foreach (self::APPROVED_REASONS as $code => $label) {
            $this->assertSame(
                $label,
                DB::table('request_reasons')->where('code', $code)->value('label_ar'),
                "Unexpected or missing label for reason code [{$code}]."
            );
        }
    }

    public function test_one_history_entry_accepts_multiple_distinct_approved_reasons(): void
    {
        [$requestId] = $this->makeRequestRow();
        $historyId = $this->makeHistory($requestId);

        $reasonIds = DB::table('request_reasons')
            ->whereIn('code', ['too_far', 'materials_unavailable'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertCount(2, $reasonIds);

        $now = now();

        DB::table('service_request_status_history_reasons')->insert([
            [
                'status_history_id' => $historyId,
                'reason_id' => $reasonIds[0],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'status_history_id' => $historyId,
                'reason_id' => $reasonIds[1],
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $persistedReasonIds = DB::table('service_request_status_history_reasons')
            ->where('status_history_id', $historyId)
            ->pluck('reason_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertEqualsCanonicalizing($reasonIds, $persistedReasonIds);
        $this->assertSame(
            2,
            DB::table('service_request_status_history_reasons')
                ->where('status_history_id', $historyId)
                ->count()
        );
    }

    public function test_duplicate_reason_per_history_entry_is_rejected_by_the_database(): void
    {
        [$requestId] = $this->makeRequestRow();
        $historyId = $this->makeHistory($requestId);
        $reasonId = DB::table('request_reasons')->where('code', 'too_far')->value('id');

        DB::table('service_request_status_history_reasons')->insert([
            'status_history_id' => $historyId,
            'reason_id' => $reasonId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('service_request_status_history_reasons')->insert([
            'status_history_id' => $historyId,
            'reason_id' => $reasonId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_histories_and_reason_links_cascade_when_a_request_is_hard_deleted(): void
    {
        [$requestId] = $this->makeRequestRow();
        $historyId = $this->makeHistory($requestId);
        $reasonId = DB::table('request_reasons')->where('code', 'materials_unavailable')->value('id');

        DB::table('service_request_status_history_reasons')->insert([
            'status_history_id' => $historyId,
            'reason_id' => $reasonId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('service_requests')->where('id', $requestId)->delete();

        $this->assertDatabaseCount('service_request_status_histories', 0);
        $this->assertDatabaseCount('service_request_status_history_reasons', 0);
    }

    public function test_a_reason_used_by_history_cannot_be_deleted(): void
    {
        [$requestId] = $this->makeRequestRow();
        $historyId = $this->makeHistory($requestId);
        $reasonId = DB::table('request_reasons')->where('code', 'other')->value('id');

        DB::table('service_request_status_history_reasons')->insert([
            'status_history_id' => $historyId,
            'reason_id' => $reasonId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('request_reasons')->where('id', $reasonId)->delete();
    }
}
