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
}
