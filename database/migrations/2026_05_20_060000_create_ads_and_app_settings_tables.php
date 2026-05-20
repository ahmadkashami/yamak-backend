<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('link')->nullable();
            $table->string('photo');
            $table->string('app_code')->unique();
            $table->dateTime('started_at')->index();
            $table->dateTime('expired_at')->index();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('android_version_code')->nullable();
            $table->string('android_recommended_version')->nullable();
            $table->string('ios_version')->nullable();
            $table->string('ios_recommended_version')->nullable();
            $table->string('test')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('ads');
    }
};
