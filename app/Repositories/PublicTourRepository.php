<?php

namespace App\Repositories;

use App\Models\Tour;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PublicTourRepository
{
    public function filtered(array $filters = [], ?int $limit = null): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->when($filters['partner_id'] ?? null, fn (Builder $query, $partnerId) => $query->where('partner_id', $partnerId))
            ->when($filters['country_id'] ?? null, fn (Builder $query, $countryId) => $query->where('country_id', $countryId))
            ->when($filters['city_id'] ?? null, fn (Builder $query, $cityId) => $query->where('city_id', $cityId))
            ->latest('id')
            ->paginate($limit);
    }

    public function baseQuery(): Builder
    {
        return Tour::query()
            ->with(['country', 'city', 'user', 'partner'])
            ->where('active', 1)
            ->fromActivePartners();
    }
}
