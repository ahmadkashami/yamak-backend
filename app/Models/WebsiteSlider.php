<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebsiteSlider extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'website_id',
        'image_url',
        'title_en',
        'title_ar',
        'sub_title_en',
        'sub_title_ar',
        'status',
    ];
}
