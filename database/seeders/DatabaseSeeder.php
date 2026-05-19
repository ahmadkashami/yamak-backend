<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Safe for live databases: master/lookup data only, written with idempotent upserts.
        $this->call(BaseDataSeeder::class);

        // Demo/testing only. Run manually when a full local demo dataset is needed:
        // php artisan db:seed --class=FullDatabaseDemoSeeder
        // $this->call(FullDatabaseDemoSeeder::class);
    }
}
