<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2.5B (change step 1) — additive request-tracking columns on service_requests.
 *
 * This migration intentionally changes no existing historical migration and
 * creates no model, service, controller or API behaviour. It only:
 *
 *   1. adds a nullable, historical-safe vehicle reference;
 *   2. adds the request channel as data (never a status);
 *   3. adds nullable request-time coordinate snapshots;
 *   4. changes the schema default status from `active` to `pending`;
 *   5. backfills existing `active` rows to `pending`.
 *
 * Historical truth rules:
 *   - `vehicle_id` is never inferred from the current driver.
 *   - `latitude` / `longitude` are never fabricated or copied from anywhere.
 *   - `channel` defaults to `app` because persisted historical requests came
 *     from the in-app flow.
 *
 * STAGING RISK (deliberate, do not "fix" here): known writers still pass an
 * explicit `status = 'active'` (RequestCreationService, Dashboard RequestController
 * store). Changing the schema default does NOT rewrite those explicit values, so
 * new rows created by those writers remain `active` until the later B2.5B
 * compatibility slice updates them. For the same reason this migration adds no
 * hard status CHECK constraint, which would break those writers immediately.
 */
return new class extends Migration
{
    /**
     * Add the new columns, move the status default to `pending`, then backfill
     * historical `active` rows. DDL precedes the data update so a row inserted
     * without an explicit status during the run already receives `pending`.
     */
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // Nullable by design: the vehicle was validated at creation but
            // never persisted, so historical rows cannot be reconstructed
            // truthfully. The FK is nulled rather than cascading so request
            // history survives a vehicle deletion.
            $table->foreignId('vehicle_id')
                ->nullable()
                ->constrained('vehicles')
                ->nullOnDelete();

            // Channel describes how the request reached YAMAK. It is data and
            // must never substitute for lifecycle status. Existing rows are
            // materialised as `app` by the non-null default.
            $table->string('channel', 20)->default('app')->index();

            // Request-time snapshots. Historical rows stay NULL; no coordinate
            // is invented. decimal(10,7) matches vehicles/app_users.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });

        // New requests start in the approved `pending` state. This is a schema
        // default change only; explicit `active` writes keep working (see the
        // staging risk above). The existing index on `status` is preserved.
        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });

        // Historical compatibility: persisted requests used the former
        // `active` state. The update intentionally includes soft-deleted rows
        // (they remain historical records). It is a single set-based statement
        // and is safe to re-run: already-converted rows are no longer matched.
        DB::table('service_requests')
            ->where('status', 'active')
            ->update(['status' => 'pending']);
    }

    /**
     * Reverses only the schema structures introduced above.
     *
     * The semantic data backfill is deliberately NOT reversible: converting
     * `pending` back to `active` would corrupt genuine pending requests created
     * after this migration. Existing row values are left untouched.
     */
    public function down(): void
    {
        // Restore the previous schema default; do not touch row data.
        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex(['channel']);
            $table->dropColumn(['channel', 'latitude', 'longitude']);
        });
    }
};
