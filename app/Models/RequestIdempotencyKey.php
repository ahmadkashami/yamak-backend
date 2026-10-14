<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency claim for the secured mobile v2 request creation path
 * (B2.2B.3B.1).
 *
 * Maps the `request_idempotency_keys` table. A row is written atomically with
 * its ServiceRequest; the pairing of `(app_user_id, key)` is enforced by a
 * database unique index. This model is intentionally inert: it exposes no
 * fingerprint algorithm, no creation orchestration and no lifecycle/status
 * behaviour. Wiring happens in a later, explicitly approved step.
 */
class RequestIdempotencyKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_user_id',
        'key',
        'request_fingerprint',
        'service_request_id',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function appUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_id');
    }

    public function serviceRequest(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }
}
