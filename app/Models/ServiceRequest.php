<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single service request.
 *
 * `latitude` / `longitude` are request-time snapshots. The `decimal:7` casts
 * preserve their stored precision and never fabricate a value. Domain-level
 * immutable-snapshot enforcement belongs to the later B2.5B
 * compatibility/lifecycle work; these models expose no coordinate mutators,
 * observers or status-transition behaviour.
 */
class ServiceRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'platform',
        'app_user_id',
        'driver_id',
        'company_id',
        'branch_id',
        'vehicle_id',
        'channel',
        'latitude',
        'longitude',
        'status',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ServiceRequestStatusHistory::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'request_service')->withTimestamps();
    }
}
