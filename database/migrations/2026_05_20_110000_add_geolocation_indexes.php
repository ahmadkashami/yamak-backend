<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->index(['latitude', 'longitude'], 'vehicles_geo_idx');
        });

        Schema::table('app_users', function (Blueprint $table) {
            $table->index(['latitude', 'longitude'], 'app_users_geo_idx');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->index(['latitude', 'longitude'], 'branches_geo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex('vehicles_geo_idx');
        });

        Schema::table('app_users', function (Blueprint $table) {
            $table->dropIndex('app_users_geo_idx');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex('branches_geo_idx');
        });
    }
};
