<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_inventory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->date('date');
            $table->unsignedSmallInteger('total_rooms');
            $table->unsignedSmallInteger('available_rooms');
            $table->unsignedSmallInteger('reserved_rooms')->default(0);
            $table->decimal('price', 10, 2);
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['room_id', 'date']);
            $table->index(['room_id', 'date', 'available_rooms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_inventory');
    }
};

