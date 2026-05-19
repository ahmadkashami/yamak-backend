<?php

namespace App\Services;

use App\Models\Partner;
use App\Repositories\PartnerRepository;
use Illuminate\Support\Str;

class PartnerRegistrationService
{
    public function __construct(private readonly PartnerRepository $partners)
    {
    }

    public function register(array $data): Partner
    {
        $name = $data['name'];
        $englishName = is_array($name) ? ($name['en'] ?? reset($name)) : $name;

        return $this->partners->create([
            'name' => $name,
            'slug' => Str::slug($englishName).'-'.Str::lower(Str::random(6)),
            'email' => $data['email'],
            'phone' => $data['phone'],
            'logo' => $data['logo'],
            'status' => 'pending',
        ]);
    }

    public function payload(array $data, ?Partner $partner = null): array
    {
        $name = $data['name'];
        $englishName = is_array($name) ? ($name['en'] ?? reset($name)) : $name;

        return [
            'name' => $name,
            'slug' => $partner?->slug ?? Str::slug($englishName).'-'.Str::lower(Str::random(6)),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'logo' => $data['logo'] ?? null,
            'status' => $data['status'] ?? $partner?->status ?? 'pending',
        ];
    }
}
