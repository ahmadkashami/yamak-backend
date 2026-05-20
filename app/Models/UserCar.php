<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserCar extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'app_user_id',
        'make',
        'model',
        'year',
        'type',
        'status',
    ];

    public function appUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }
}
