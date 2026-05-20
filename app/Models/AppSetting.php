<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'android_version_code',
        'android_recommended_version',
        'ios_version',
        'ios_recommended_version',
        'test',
    ];
}
