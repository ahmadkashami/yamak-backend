<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class City extends Model
{
    use HasFactory, SoftDeletes, Imageable;

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        "featured" => "boolean",
        "status" => "boolean",
    ];
    protected $with = ['country'];
    protected $fillable = [
        'name', 'description', 'country_id', 'featured', 'cover_image', 'status'
    ];

    public function country(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function Places(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Place::class);
    }

    public function scopeActiveCities($query)
    {
        return $query->where('status', 1);
    }
}
