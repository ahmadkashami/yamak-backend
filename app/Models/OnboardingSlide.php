<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class OnboardingSlide extends Model
{
    use HasFactory;

    protected $fillable = [
        'onboarding_id',
        'sort_order',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function image(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }
}
