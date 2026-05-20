<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebsiteTheme extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'website_id',
        'primary_color',
        'secondary_color',
        'secondary_background_color',
        'banner_image',
        'status',
    ];

    public function website(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
