<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class BaseDataSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->roles() as $role) {
            Role::query()->updateOrCreate(
                ['name' => $role['name'], 'guard_name' => 'web'],
                ['display_name' => $role['display_name']]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function roles(): array
    {
        return [
            ['name' => 'SUPER_ADMIN',        'display_name' => 'Super Admin'],
            ['name' => 'ADMIN',              'display_name' => 'Admin'],
            ['name' => 'SALES',              'display_name' => 'Sales'],
            ['name' => 'CUSTOMER_SERVICES',  'display_name' => 'Customer Services'],
        ];
    }
}
