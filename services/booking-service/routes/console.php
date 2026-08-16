<?php

use App\Models\OutboxMessage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('outbox:publish {--limit=50} {--dry-run}', function () {
    $limit = max(1, min((int) $this->option('limit'), 500));

    $messages = OutboxMessage::query()
        ->where('status', OutboxMessage::STATUS_PENDING)
        ->where('available_at', '<=', now())
        ->orderBy('id')
        ->limit($limit)
        ->get();

    foreach ($messages as $message) {
        $this->line("{$message->topic} {$message->type} {$message->event_id}");

        if ($this->option('dry-run')) {
            continue;
        }

        Log::info('Publishing outbox event to Kafka boundary', [
            'topic' => $message->topic,
            'event_id' => $message->event_id,
            'type' => $message->type,
            'payload' => $message->payload,
        ]);

        $message->update([
            'status' => OutboxMessage::STATUS_PUBLISHED,
            'attempts' => $message->attempts + 1,
            'published_at' => now(),
            'last_error' => null,
        ]);
    }

    $this->info("Processed {$messages->count()} outbox message(s).");
})->purpose('Publish pending transactional outbox messages to the Kafka boundary');
