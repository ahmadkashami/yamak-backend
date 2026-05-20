<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->string('name');
            $table->string('photo')->nullable();
            $table->string('phone_num')->nullable()->index();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->string('driver_license')->nullable();
            $table->date('license_expired_at')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamp('last_login')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'branch_id']);
            $table->index(['name', 'phone_num']);
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('current_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->string('app_code')->unique();
            $table->string('status')->default('active')->index();
            $table->string('work_status')->default('offline')->index();
            $table->timestamp('last_active_date')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('logo')->nullable();
            $table->string('type')->nullable();
            $table->string('icon_map')->nullable();
            $table->string('label_number')->nullable();
            $table->string('model_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'branch_id']);
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
        });

        Schema::create('driver_vehicle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['driver_id', 'vehicle_id']);
        });

        Schema::create('vehicle_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vehicle_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_service');
        Schema::dropIfExists('driver_vehicle');

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
        });

        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('drivers');
    }
};
