<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')->nullable()->constrained('app_users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->text('description')->nullable();
            $table->string('type')->nullable()->index();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('expired_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
