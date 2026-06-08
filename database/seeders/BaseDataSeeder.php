<?php

namespace Database\Seeders;

use App\Models\AppUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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

        $adminRole = Role::query()->where('name', 'SUPER_ADMIN')->first();

        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'     => 'Admin User',
                'password' => Hash::make('123456'),
                'mobile'   => null,
                'type'     => 'admin',
                'role_id'  => $adminRole?->id,
            ]
        );

        AppUser::query()->updateOrCreate(
            ['email' => 'app.user@example.com'],
            [
                'name'              => 'Test App User',
                'password'          => Hash::make('123456'),
                'phone'             => '96550535103',
                'is_email_verified' => true,
                'status'            => 'active',
            ]
        );
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
