<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\OutboxMessage;
use Illuminate\Support\Str;

class OutboxRecorder
{
    /**
     * @param array<string, mixed> $payload
     */
    public function recordBookingEvent(Booking $booking, string $type, array $payload = []): OutboxMessage
    {
        return $this->record(
            topic: 'booking-events',
            type: $type,
            aggregateType: 'booking',
            aggregateId: (string) $booking->id,
            correlationId: $booking->saga_id ?? (string) Str::uuid(),
            payload: [
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
                'hotel_id' => $booking->hotel_id,
                'room_id' => $booking->room_id,
                'status' => $booking->status,
                'saga_state' => $booking->saga_state,
                ...$payload,
            ],
        );
    }

    public function recordNotificationRequested(Booking $booking, string $type, string $subject, string $body): OutboxMessage
    {
        return $this->record(
            topic: 'notification-events',
            type: 'notification.requested',
            aggregateType: 'booking',
            aggregateId: (string) $booking->id,
            correlationId: $booking->saga_id ?? (string) Str::uuid(),
            payload: [
                'recipient_user_id' => $booking->user_id,
                'channel' => 'email',
                'type' => $type,
                'subject' => $subject,
                'body' => $body,
                'payload' => [
                    'booking_id' => $booking->id,
                    'hotel_id' => $booking->hotel_id,
                    'room_id' => $booking->room_id,
                    'payment_id' => $booking->payment_id,
                    'status' => $booking->status,
                ],
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function record(
        string $topic,
        string $type,
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        array $payload,
    ): OutboxMessage {
        $eventId = (string) Str::uuid();

        return OutboxMessage::query()->create([
            'event_id' => $eventId,
            'topic' => $topic,
            'type' => $type,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'correlation_id' => $correlationId,
            'payload' => [
                'event_id' => $eventId,
                'type' => $type,
                'version' => 1,
                'correlation_id' => $correlationId,
                'aggregate_id' => $aggregateId,
                'occurred_at' => now()->toISOString(),
                'payload' => $payload,
            ],
            'headers' => [
                'event_id' => $eventId,
                'type' => $type,
                'correlation_id' => $correlationId,
            ],
        ]);
    }
}
