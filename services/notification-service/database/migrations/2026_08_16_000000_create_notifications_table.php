<?php

use App\Models\NotificationMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('recipient_user_id');
            $table->string('channel', 30);
            $table->string('type', 80);
            $table->string('subject', 160);
            $table->text('body');
            $table->json('payload')->nullable();
            $table->string('status', 30)->default(NotificationMessage::STATUS_QUEUED);
            $table->string('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_user_id', 'status']);
            $table->index(['recipient_user_id', 'read_at']);
            $table->index(['channel', 'status']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
