<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeds the 4 system roles required by the mongo dump import.
        // After this, run: php artisan yamak:seed-mongo-dump --clean
        $this->call(BaseDataSeeder::class);
    }
}
