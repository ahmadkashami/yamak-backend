<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('logo')->nullable();
            $table->string('domain_url')->unique();
            $table->text('about_us_en')->nullable();
            $table->text('about_us_ar')->nullable();
            $table->text('contact_address_en')->nullable();
            $table->text('contact_address_ar')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'domain_url']);
        });

        Schema::create('website_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['website_id', 'service_id']);
        });

        Schema::create('website_themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('primary_color');
            $table->string('secondary_color');
            $table->string('secondary_background_color')->nullable();
            $table->string('banner_image')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('website_id');
        });

        Schema::create('website_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('icon');
            $table->string('title_en')->nullable();
            $table->string('title_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'website_id']);
        });

        Schema::create('website_sliders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('image_url');
            $table->string('title_en')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('sub_title_en')->nullable();
            $table->string('sub_title_ar')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'website_id']);
        });

        Schema::create('website_carousels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('image_url');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_carousels');
        Schema::dropIfExists('website_sliders');
        Schema::dropIfExists('website_features');
        Schema::dropIfExists('website_themes');
        Schema::dropIfExists('website_service');
        Schema::dropIfExists('websites');
    }
};
