<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('room_type', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->decimal('base_price', 10, 2);
            $table->char('currency', 3);
            $table->json('amenities')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hotel_id', 'room_type']);
            $table->index(['hotel_id', 'status']);
            $table->index('base_price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};

