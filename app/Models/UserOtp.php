<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserOtp extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'email',
        'code',
        'code_expire_date',
    ];

    protected $casts = [
        'code_expire_date' => 'datetime',
    ];
}
