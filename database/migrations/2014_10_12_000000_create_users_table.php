<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
      Schema::create('users', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->text('name');
    $table->string('email')->unique();
    $table->string('mobile')->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->string('type')->nullable();
    $table->string('lang', 50)->nullable();
    $table->string('avatar')->nullable();
    $table->unsignedBigInteger('role_id')->nullable();
    
    // Foreign key constraint
    $table->foreign('role_id')->references('id')->on('roles')->onDelete('set null');
    
    $table->softDeletes();
    $table->rememberToken();
    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
