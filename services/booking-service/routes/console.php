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

Artisan::command('saga:retry-compensations {--limit=25}', function (App\Services\InventoryClient $inventory, App\Services\OutboxRecorder $outbox) {
    $limit = max(1, min((int) $this->option('limit'), 100));

    $bookings = App\Models\Booking::query()
        ->where('saga_state', App\Models\Booking::SAGA_COMPENSATION_FAILED)
        ->orderBy('id')
        ->limit($limit)
        ->get();

    foreach ($bookings as $booking) {
        $this->line("Retrying compensation for booking #{$booking->id}");

        $release = $inventory->post('/api/inventory/releases', [
            'room_id' => $booking->room_id,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'quantity' => $booking->quantity,
        ]);

        if ($release instanceof Illuminate\Http\JsonResponse) {
            $booking->update([
                'saga_error' => 'Inventory release compensation retry failed.',
            ]);

            $outbox->recordBookingEvent($booking->refresh(), 'booking.compensation_retry_failed', [
                'compensation' => 'inventory_release_failed',
            ]);

            continue;
        }

        Illuminate\Support\Facades\DB::transaction(function () use ($booking, $outbox) {
            $booking->update([
                'saga_state' => App\Models\Booking::SAGA_COMPENSATED,
                'saga_error' => null,
                'compensated_at' => now(),
            ]);

            $outbox->recordBookingEvent($booking->refresh(), 'booking.compensation_recovered', [
                'compensation' => 'inventory_released',
            ]);
        });
    }

    $this->info("Checked {$bookings->count()} failed compensation(s).");
})->purpose('Retry failed booking Saga compensations');
