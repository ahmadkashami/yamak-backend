<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2.5B (change step 1) — request lifecycle history and the fixed reason catalog.
 *
 * Creates only new, additive structures:
 *   - service_request_status_histories  (one immutable event per transition)
 *   - request_reasons                   (fixed, stable-code catalog)
 *   - service_request_status_history_reasons (many reasons per history entry)
 *
 * Nothing here creates synthetic history for historical requests, fabricates an
 * actor, or guesses a reason. Reasons attach to a history entry, never directly
 * to a service request. Business validation (which statuses require reasons,
 * `other` requiring a note) belongs to the later B2.5B lifecycle slice; this
 * migration enforces relational integrity and pivot uniqueness only.
 *
 * The status vocabulary is stored as plain strings on purpose. No CHECK
 * constraint is added, because existing code still writes `active` explicitly
 * until the later compatibility update.
 */
return new class extends Migration
{
    /**
     * The approved catalog. Codes are stable database keys; labels are the
     * approved Arabic meanings. No English translations are invented and no
     * additional codes are added here.
     *
     * @var array<int, array{code: string, label_ar: string}>
     */
    private const REASONS = [
        ['code' => 'too_far', 'label_ar' => 'المكان بعيد'],
        ['code' => 'service_cannot_be_provided', 'label_ar' => 'لا أستطيع تقديم الخدمة'],
        ['code' => 'materials_unavailable', 'label_ar' => 'المواد غير متوفرة'],
        ['code' => 'customer_unreachable', 'label_ar' => 'تعذر التواصل مع العميل'],
        ['code' => 'customer_cancelled', 'label_ar' => 'العميل ألغى الطلب'],
        ['code' => 'provider_unavailable', 'label_ar' => 'مقدم الخدمة غير متاح'],
        ['code' => 'service_unavailable', 'label_ar' => 'الخدمة المطلوبة غير متوفرة'],
        ['code' => 'location_inaccessible', 'label_ar' => 'موقع العميل غير قابل للوصول'],
        ['code' => 'price_not_agreed', 'label_ar' => 'لم يتم الاتفاق على السعر'],
        ['code' => 'other', 'label_ar' => 'سبب آخر'],
    ];

    public function up(): void
    {
        Schema::create('service_request_status_histories', function (Blueprint $table) {
            $table->id();

            // Hard-deleting a request removes its history. Soft deletes leave
            // history intact, which is the normal path.
            $table->foreignId('service_request_id')
                ->constrained('service_requests')
                ->cascadeOnDelete();

            // Initial history rows have no previous state (NULL from_status).
            $table->string('from_status')->nullable();
            $table->string('to_status');

            // Approved actors: system, app_user, admin, provider. Deliberately
            // simple columns, not a polymorphic actor relation.
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();

            // Optional note. `other` will require it at the application layer
            // in the later lifecycle slice.
            $table->text('reason_note')->nullable();

            // Immutable event timestamp; this table has no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // Chronological history per request. The leftmost column also
            // backs the foreign key.
            $table->index(
                ['service_request_id', 'created_at'],
                'srs_histories_request_created_idx'
            );

            // Supports lifecycle/reporting filters by destination status.
            $table->index('to_status', 'srs_histories_to_status_idx');
        });

        Schema::create('request_reasons', function (Blueprint $table) {
            $table->id();

            // Stable, unique catalog key. Never rename an implemented code
            // without an approved change.
            $table->string('code', 64)->unique();

            // Only the approved Arabic user-facing meaning is recorded.
            $table->string('label_ar');

            $table->timestamps();
        });

        Schema::create('service_request_status_history_reasons', function (Blueprint $table) {
            $table->id();

            // Deliberately short column name so every generated identifier
            // stays within MySQL's 64-character limit; explicit names below
            // make that guarantee independent of Laravel's naming strategy.
            $table->unsignedBigInteger('status_history_id');
            $table->unsignedBigInteger('reason_id');

            $table->timestamps();

            // One history entry may carry several distinct reasons, but never
            // the same reason twice.
            $table->unique(
                ['status_history_id', 'reason_id'],
                'srs_history_reason_unique'
            );

            // Supports reverse lookups/reporting by reason; the unique index
            // already covers the leftmost status_history_id for its FK.
            $table->index('reason_id', 'srs_history_reason_reason_idx');

            $table->foreign('status_history_id', 'srs_history_reason_history_fk')
                ->references('id')
                ->on('service_request_status_histories')
                ->cascadeOnDelete();

            // A catalog reason referenced by history must not disappear.
            $table->foreign('reason_id', 'srs_history_reason_reason_fk')
                ->references('id')
                ->on('request_reasons')
                ->restrictOnDelete();
        });

        // Fixed catalog seed. insertOrIgnore keeps a recovery re-run safe and
        // never overwrites an operator-edited label. Extra codes cannot appear
        // here because this is the single source for the catalog.
        $now = now();
        $rows = array_map(
            fn (array $reason): array => $reason + ['created_at' => $now, 'updated_at' => $now],
            self::REASONS,
        );

        DB::table('request_reasons')->insertOrIgnore($rows);
    }

    /**
     * Drops only the tables introduced by this migration, child table first.
     * No service_requests data is modified.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_request_status_history_reasons');
        Schema::dropIfExists('service_request_status_histories');
        Schema::dropIfExists('request_reasons');
    }
};
