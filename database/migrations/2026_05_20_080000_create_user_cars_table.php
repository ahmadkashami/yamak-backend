<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_cars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')->constrained('app_users')->cascadeOnDelete();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->string('year')->nullable();
            $table->string('type')->default('Car');
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_cars');
    }
};
