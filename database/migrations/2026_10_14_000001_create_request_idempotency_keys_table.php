<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2.2B.3B.1 — idempotency claim storage for the secured mobile v2 request
 * creation path.
 *
 * One row is written, atomically with its ServiceRequest, for every accepted
 * v2 request. The row is scoped to the authenticated AppUser and carries a
 * SHA-256 fingerprint of the canonical request payload so an identical
 * `Idempotency-Key` can be replayed without creating a second request.
 *
 * `service_request_id` is NOT NULL by design: this table only ever stores rows
 * for fully committed requests (the caller inserts this row and the request in
 * the same transaction), so there is no processing/status lifecycle column.
 *
 * `expires_at` is a future cleanup hint only. An expired-but-present row must
 * still replay; expiration never authorizes creating another request.
 *
 * No table is created or altered outside this migration definition, and the
 * migration is intentionally not executed as part of this step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_idempotency_keys', function (Blueprint $table) {
            $table->id();

            // Owner scope: an idempotency key is only meaningful within the
            // authenticated AppUser. CASCADE mirrors service_requests.app_user_id.
            $table->foreignId('app_user_id')->constrained('app_users')->cascadeOnDelete();

            // Client-supplied UUID v4 (stored lowercased). `key` is a reserved
            // word in MySQL, but Laravel's grammar quotes identifiers.
            $table->char('key', 36);

            // Lowercase hex SHA-256 of the canonical payload (authenticated user,
            // vehicle, driver, sorted-unique service ids). Detects the
            // same-key/different-payload case.
            $table->char('request_fingerprint', 64);

            // The committed request this key resolves to. NOT NULL: rows only
            // exist for committed requests. CASCADE mirrors
            // request_service.service_request_id.
            $table->foreignId('service_request_id')->constrained('service_requests')->cascadeOnDelete();

            // Future cleanup hint (the 24h default is applied by the creation
            // service, not by the schema). Never a uniqueness or replay gate.
            $table->timestamp('expires_at');

            $table->timestamps();

            // Idempotency is scoped per owner; the database, not PHP, enforces
            // it. This unique index also serves as the leftmost-prefix index for
            // the app_user_id foreign key.
            $table->unique(['app_user_id', 'key']);

            // Supports a future prune command. The service_request_id foreign key
            // is indexed implicitly by MySQL/InnoDB, so no redundant index is added.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_idempotency_keys');
    }
};
