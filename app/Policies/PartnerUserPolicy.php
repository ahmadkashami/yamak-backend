<?php

namespace App\Policies;

use App\Models\User;
use App\Support\TenantAccess;

class PartnerUserPolicy
{
    public function view(User $authUser, User $targetUser): bool
    {
        return TenantAccess::isSuperAdmin($authUser)
            || (int) $authUser->partner_id === (int) $targetUser->partner_id;
    }

    public function update(User $authUser, User $targetUser): bool
    {
        return $this->view($authUser, $targetUser);
    }
}
