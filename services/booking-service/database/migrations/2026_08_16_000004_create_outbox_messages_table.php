<?php

use App\Models\OutboxMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('topic', 120);
            $table->string('type', 120);
            $table->string('aggregate_type', 80);
            $table->string('aggregate_id', 80);
            $table->uuid('correlation_id');
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->string('status', 30)->default(OutboxMessage::STATUS_PENDING);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
