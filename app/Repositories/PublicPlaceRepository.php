<?php

namespace App\Repositories;

use App\Models\Place;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PublicPlaceRepository
{
    public function filtered(array $filters = [], ?int $limit = null): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->when($filters['partner_id'] ?? null, fn (Builder $query, $partnerId) => $query->where('partner_id', $partnerId))
            ->when($filters['country_id'] ?? null, function (Builder $query, $countryId) {
                $query->whereHas('city', fn (Builder $cityQuery) => $cityQuery->where('country_id', $countryId));
            })
            ->when($filters['city_id'] ?? null, fn (Builder $query, $cityId) => $query->where('city_id', $cityId))
            ->latest('id')
            ->paginate($limit);
    }

    public function baseQuery(): Builder
    {
        return Place::query()
            ->activePlaces()
            ->fromActivePartners()
            ->with(['city.country', 'placeType', 'categoryPlaces', 'featurePlaces', 'partner']);
    }
}
