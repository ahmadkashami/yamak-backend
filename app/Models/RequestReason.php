<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A fixed request-status reason from the approved catalog.
 *
 * Maps `request_reasons`. Codes and Arabic labels are seeded once by the
 * B2.5B migration and must not be renamed or extended here. This model only
 * exposes the inverse many-to-many relation; reason-selection validation
 * belongs to the later B2.5B lifecycle work.
 */
class RequestReason extends Model
{
    use HasFactory;

    protected $table = 'request_reasons';

    protected $fillable = [
        'code',
        'label_ar',
    ];

    public function statusHistories(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceRequestStatusHistory::class,
            'service_request_status_history_reasons',
            'reason_id',
            'status_history_id',
        )->withTimestamps();
    }
}
