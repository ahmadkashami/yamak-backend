<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable()->index();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('phone')->nullable()->index();
            $table->string('photo')->nullable();
            $table->boolean('notifications_enabled')->default(false);
            $table->string('notification_token')->nullable();
            $table->string('gender')->default('male');
            $table->boolean('is_email_verified')->default(false);
            $table->string('reset_pass_code')->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestamp('reset_pass_expire_date')->nullable();
            $table->boolean('is_social_signup')->default(false);
            $table->date('birth_day')->nullable();
            $table->timestamp('last_login')->nullable();
            $table->string('language')->default('en');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->default('mobile')->index();
            $table->foreignId('app_user_id')->constrained('app_users')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_user_id', 'driver_id']);
        });

        Schema::create('request_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained('service_requests')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['service_request_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_service');
        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('app_users');
    }
};
