<?php

namespace App\Policies;

use App\Models\Tour;
use App\Models\User;
use App\Support\TenantAccess;

class TourPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tour $tour): bool
    {
        return TenantAccess::canAccessPartnerModel($tour, $user);
    }

    public function create(User $user): bool
    {
        return TenantAccess::isSuperAdmin($user) || filled($user->partner_id);
    }

    public function update(User $user, Tour $tour): bool
    {
        return TenantAccess::canAccessPartnerModel($tour, $user);
    }

    public function delete(User $user, Tour $tour): bool
    {
        return TenantAccess::canAccessPartnerModel($tour, $user);
    }
}
