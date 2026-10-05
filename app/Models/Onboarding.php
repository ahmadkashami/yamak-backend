<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Onboarding extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'version',
        'status',
        'is_active',
        'is_skippable',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_skippable' => 'boolean',
        'version' => 'integer',
    ];

    public function slides(): HasMany
    {
        return $this->hasMany(OnboardingSlide::class)->orderBy('sort_order');
    }
}
