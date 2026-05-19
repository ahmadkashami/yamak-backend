<?php

namespace App\Repositories;

use App\Models\Partner;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PartnerRepository
{
    public function paginate(?string $search = null, ?int $limit = null): LengthAwarePaginator
    {
        return $this->query()
            ->when($search, function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name->en', 'like', '%'.$search.'%')
                        ->orWhere('name->ar', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->latest()
            ->paginate($limit);
    }

    public function create(array $data): Partner
    {
        return $this->query()->create($data);
    }

    private function query(): Builder
    {
        return Partner::query();
    }
}
