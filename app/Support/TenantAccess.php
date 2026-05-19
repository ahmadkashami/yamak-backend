<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantAccess
{
    public static function isSuperAdmin(?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user) {
            return false;
        }

        return blank($user->partner_id) && in_array($user->role?->name, ['Super Admin', 'Admin'], true);
    }

    public static function scopeToPartner(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user || self::isSuperAdmin($user)) {
            return $query;
        }

        abort_if(blank($user->partner_id), 403);

        return $query->where('partner_id', $user->partner_id);
    }

    public static function canAccessPartnerModel(Model $model, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user || self::isSuperAdmin($user)) {
            return true;
        }

        return filled($user->partner_id) && (int) $model->partner_id === (int) $user->partner_id;
    }

    public static function partnerIdForCreate(?User $user = null, mixed $requestedPartnerId = null): mixed
    {
        $user ??= auth()->user();

        if (! $user) {
            return $requestedPartnerId;
        }

        if (self::isSuperAdmin($user)) {
            return $requestedPartnerId;
        }

        abort_if(blank($user->partner_id), 403);

        return $user->partner_id;
    }
}
