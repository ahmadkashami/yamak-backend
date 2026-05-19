<?php

namespace Database\Seeders;

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
                [
                    'name' => $role['name'],
                    'guard_name' => 'web',
                ],
                [
                    'display_name' => $role['display_name'],
                ]
            );
        }

        $this->seedDemoAdmin();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function roles(): array
    {
        return [
            ['name' => 'SUPER_ADMIN', 'display_name' => 'Super Admin'],
            ['name' => 'ADMIN', 'display_name' => 'Admin'],
            ['name' => 'SALES', 'display_name' => 'Sales'],
            ['name' => 'CUSTOMER_SERVICES', 'display_name' => 'Customer Services'],
        ];
    }

    private function seedDemoAdmin(): void
    {
        $roleId = Role::query()
            ->where('name', 'SUPER_ADMIN')
            ->where('guard_name', 'web')
            ->value('id');

        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => ['en' => 'Demo Admin', 'ar' => 'Demo Admin'],
                'mobile' => '500000001',
                'password' => Hash::make('123456'),
                'type' => 'dashboard',
                'lang' => 'en',
                'role_id' => $roleId,
                'email_verified_at' => now(),
            ]
        );
    }
}
