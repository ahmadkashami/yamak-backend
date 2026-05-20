<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_otps', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('code');
            $table->timestamp('code_expire_date')->nullable()->index();
            $table->timestamps();

            $table->unique(['phone', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_otps');
    }
};
