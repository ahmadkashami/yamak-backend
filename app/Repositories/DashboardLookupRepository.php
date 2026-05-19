<?php

namespace App\Repositories;

use App\Models\CategoryPlace;
use App\Models\City;
use App\Models\Country;
use App\Models\FeaturePlace;
use App\Models\PlaceType;
use Illuminate\Support\Collection;

class DashboardLookupRepository
{
    public function placeFormData(): array
    {
        return [
            'countries' => $this->countries(),
            'cities' => $this->cities(),
            'placeTypes' => $this->placeTypes(),
            'categoryPlaces' => $this->categoryPlaces(),
            'featurePlaces' => $this->featurePlaces(),
        ];
    }

    public function tourFormData(): array
    {
        return [
            'countries' => $this->countries(),
            'cities' => $this->cities(),
        ];
    }

    private function countries(): Collection
    {
        return Country::query()
            ->select(['id', 'name', 'status'])
            ->orderBy('id')
            ->get();
    }

    private function cities(): Collection
    {
        return City::query()
            ->select(['id', 'name', 'country_id', 'status'])
            ->orderBy('country_id')
            ->orderBy('id')
            ->get();
    }

    private function placeTypes(): Collection
    {
        return PlaceType::query()
            ->select(['id', 'name', 'slug', 'icon'])
            ->orderBy('id')
            ->get();
    }

    private function categoryPlaces(): Collection
    {
        return CategoryPlace::query()
            ->select(['id', 'name'])
            ->orderBy('id')
            ->get();
    }

    private function featurePlaces(): Collection
    {
        return FeaturePlace::query()
            ->select(['id', 'name'])
            ->orderBy('id')
            ->get();
    }
}
