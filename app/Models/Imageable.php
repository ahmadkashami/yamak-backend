<?php

namespace App\Models;

use App\Models;

trait Imageable
{
    public function images(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function handelSaveImages($path, $description): void
    {
        $this->images()->updateOrCreate([
            'path' => $path,
        ], [
            'description' => $description
        ]);
    }
}
