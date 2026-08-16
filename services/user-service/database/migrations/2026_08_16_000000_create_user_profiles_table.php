<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('keycloak_user_id', 120)->unique();
            $table->string('email')->unique();
            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('phone', 40)->nullable();
            $table->string('role', 40);
            $table->string('locale', 12)->default('en');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('role');
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
