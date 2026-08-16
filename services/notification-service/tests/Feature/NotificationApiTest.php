<?php

namespace Tests\Feature;

use App\Models\NotificationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_can_be_sent_and_marked_read(): void
    {
        $notificationId = $this->postJson('/api/notifications', [
            'source_event_id' => '72e55fdb-2f7c-4699-bf3b-6e7efd07dd0d',
            'recipient_user_id' => 1,
            'channel' => 'email',
            'type' => 'booking.confirmed',
            'subject' => 'Booking confirmed',
            'body' => 'Your booking is confirmed.',
            'payload' => ['booking_id' => 1],
        ])->assertCreated()
            ->assertJsonPath('data.status', NotificationMessage::STATUS_QUEUED)
            ->json('data.id');

        $this->postJson("/api/notifications/{$notificationId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', NotificationMessage::STATUS_SENT);

        $this->postJson("/api/notifications/{$notificationId}/fail", [
            'failure_reason' => 'SMTP unavailable',
        ])->assertUnprocessable();

        $this->postJson("/api/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notificationId);

        $this->assertNotNull(NotificationMessage::query()->find($notificationId)->read_at);
    }

    public function test_notification_creation_is_idempotent_by_source_event_id(): void
    {
        $payload = [
            'source_event_id' => '118d48d2-f302-41af-a055-9e159273dafe',
            'recipient_user_id' => 1,
            'channel' => 'email',
            'type' => 'booking.pending_payment',
            'subject' => 'Booking pending payment',
            'body' => 'Complete payment to confirm your booking.',
            'payload' => ['booking_id' => 1],
        ];

        $notificationId = $this->postJson('/api/notifications', $payload)
            ->assertCreated()
            ->assertJsonPath('data.source_event_id', '118d48d2-f302-41af-a055-9e159273dafe')
            ->assertJsonPath('meta.idempotent_replay', false)
            ->json('data.id');

        $this->postJson('/api/notifications', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $notificationId)
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseCount('notifications', 1);
    }
}
