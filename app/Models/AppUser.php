<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class AppUser extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'photo',
        'notifications_enabled',
        'notification_token',
        'gender',
        'is_email_verified',
        'reset_pass_code',
        'fcm_token',
        'reset_pass_expire_date',
        'is_social_signup',
        'birth_day',
        'last_login',
        'language',
        'latitude',
        'longitude',
        'status',
    ];

    protected $casts = [
        'password' => 'hashed',
        'notifications_enabled' => 'boolean',
        'is_email_verified' => 'boolean',
        'is_social_signup' => 'boolean',
        'birth_day' => 'date',
        'last_login' => 'datetime',
        'reset_pass_expire_date' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function cars(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserCar::class);
    }

    public function notifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function requests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }
}
