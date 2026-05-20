<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppUser extends Model
{
    use HasFactory, SoftDeletes;

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
}
