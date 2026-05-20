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
            ->select(['id', 'name_en', 'name_ar', 'status'])
            ->orderBy('id')
            ->get()
            ->map(fn (Country $country) => [
                'id' => $country->id,
                '_id' => (string) $country->id,
                'name' => ['en' => $country->name_en, 'ar' => $country->name_ar],
                'status' => $country->status,
            ]);
    }

    private function cities(): Collection
    {
        return City::query()
            ->select(['id', 'name_en', 'name_ar', 'state_id', 'status'])
            ->orderBy('state_id')
            ->orderBy('id')
            ->get()
            ->map(fn (City $city) => [
                'id' => $city->id,
                '_id' => (string) $city->id,
                'name' => ['en' => $city->name_en, 'ar' => $city->name_ar],
                'stateId' => (string) $city->state_id,
                'status' => $city->status,
            ]);
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
