<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One immutable request-status event.
 *
 * Maps `service_request_status_histories`. The table records only `created_at`,
 * so `UPDATED_AT` is disabled while normal timestamps stay enabled for
 * insertion of the event time. This model intentionally provides no
 * status-transition methods and no actor authentication/polymorphic relation;
 * writing history and enforcing lifecycle rules belongs to the later B2.5B
 * compatibility/lifecycle work.
 */
class ServiceRequestStatusHistory extends Model
{
    use HasFactory;

    /**
     * The table has no `updated_at` column; keep `created_at` automatic.
     */
    public const UPDATED_AT = null;

    protected $table = 'service_request_status_histories';

    protected $fillable = [
        'service_request_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'reason_note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function reasons(): BelongsToMany
    {
        return $this->belongsToMany(
            RequestReason::class,
            'service_request_status_history_reasons',
            'status_history_id',
            'reason_id',
        )->withTimestamps();
    }
}
