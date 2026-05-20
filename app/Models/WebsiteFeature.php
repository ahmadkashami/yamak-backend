<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebsiteFeature extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'website_id',
        'icon',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
        'status',
    ];
}
