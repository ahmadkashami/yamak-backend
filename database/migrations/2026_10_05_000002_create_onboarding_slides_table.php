<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->constrained('onboardings')->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->string('title_en');
            $table->string('title_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->timestamps();

            $table->unique(['onboarding_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_slides');
    }
};
