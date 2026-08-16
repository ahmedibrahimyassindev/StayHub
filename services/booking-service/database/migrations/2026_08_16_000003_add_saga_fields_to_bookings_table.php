<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->uuid('saga_id')->nullable()->after('idempotency_key');
            $table->string('saga_state', 40)->nullable()->after('saga_id');
            $table->timestamp('compensated_at')->nullable()->after('saga_state');
            $table->index(['saga_id', 'saga_state']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['saga_id', 'saga_state']);
            $table->dropColumn(['saga_id', 'saga_state', 'compensated_at']);
        });
    }
};
