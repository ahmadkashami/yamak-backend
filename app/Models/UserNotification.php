<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserNotification extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'app_user_id',
        'title',
        'body',
        'description',
        'type',
        'is_read',
        'expired_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'expired_at' => 'datetime',
    ];

    public function appUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }
}
