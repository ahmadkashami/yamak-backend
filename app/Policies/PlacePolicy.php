<?php

namespace App\Policies;

use App\Models\Place;
use App\Models\User;
use App\Support\TenantAccess;

class PlacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Place $place): bool
    {
        return TenantAccess::canAccessPartnerModel($place, $user);
    }

    public function create(User $user): bool
    {
        return TenantAccess::isSuperAdmin($user) || filled($user->partner_id);
    }

    public function update(User $user, Place $place): bool
    {
        return TenantAccess::canAccessPartnerModel($place, $user);
    }

    public function delete(User $user, Place $place): bool
    {
        return TenantAccess::canAccessPartnerModel($place, $user);
    }
}
