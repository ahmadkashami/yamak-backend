<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Country extends Model
{
    use HasFactory,SoftDeletes,Imageable;
    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'when_to_visit' => 'array',
        'status' => 'boolean',
        'featured' => 'boolean',
    ];
    protected $fillable = [
        'name', 'description','featured', 'cover_image','flag_image','status','when_to_visit'
    ];

    public function cities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(City::class );
    }


}
