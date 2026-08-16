<?php

namespace Tests\Feature;

use App\Models\NotificationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_HEADERS = [
        'X-Service-Token' => 'test-internal-token',
        'X-StayHub-Service-Token' => 'test-internal-token',
        'Authorization' => 'Service test-internal-token',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.internal.token' => 'test-internal-token',
            'services.keycloak.allow_test_identity_headers' => true,
        ]);
    }

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
        ], self::SERVICE_HEADERS)->assertCreated()
            ->assertJsonPath('data.status', NotificationMessage::STATUS_QUEUED)
            ->json('data.id');

        $this->postJson("/api/notifications/{$notificationId}/send", [], self::SERVICE_HEADERS)
            ->assertOk()
            ->assertJsonPath('data.status', NotificationMessage::STATUS_SENT);

        $this->postJson("/api/notifications/{$notificationId}/fail", [
            'failure_reason' => 'SMTP unavailable',
        ], self::SERVICE_HEADERS)->assertUnprocessable();

        $this->postJson("/api/notifications/{$notificationId}/read", [], [
            'X-Test-User-Id' => '1',
        ])
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

        $notificationId = $this->postJson('/api/notifications', $payload, self::SERVICE_HEADERS)
            ->assertCreated()
            ->assertJsonPath('data.source_event_id', '118d48d2-f302-41af-a055-9e159273dafe')
            ->assertJsonPath('meta.idempotent_replay', false)
            ->json('data.id');

        $this->postJson('/api/notifications', $payload, self::SERVICE_HEADERS)
            ->assertOk()
            ->assertJsonPath('data.id', $notificationId)
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_customer_only_lists_own_notifications(): void
    {
        NotificationMessage::query()->create([
            'recipient_user_id' => 1,
            'channel' => 'email',
            'type' => 'booking.confirmed',
            'subject' => 'Booking confirmed',
            'body' => 'Your booking is confirmed.',
        ]);
        NotificationMessage::query()->create([
            'recipient_user_id' => 2,
            'channel' => 'email',
            'type' => 'booking.confirmed',
            'subject' => 'Booking confirmed',
            'body' => 'Your booking is confirmed.',
        ]);

        $this->getJson('/api/notifications', [
            'X-Test-User-Id' => '1',
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recipient_user_id', 1);
    }

    public function test_customer_cannot_read_another_users_notification(): void
    {
        $notification = NotificationMessage::query()->create([
            'recipient_user_id' => 1,
            'channel' => 'email',
            'type' => 'booking.confirmed',
            'subject' => 'Booking confirmed',
            'body' => 'Your booking is confirmed.',
        ]);

        $this->getJson("/api/notifications/{$notification->id}", [
            'X-Test-User-Id' => '2',
        ])->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to access this notification.');
    }

    public function test_customer_cannot_create_notifications(): void
    {
        $this->postJson('/api/notifications', [
            'recipient_user_id' => 1,
            'channel' => 'email',
            'type' => 'booking.confirmed',
            'subject' => 'Booking confirmed',
            'body' => 'Your booking is confirmed.',
        ], [
            'X-Test-User-Id' => '1',
        ])->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to manage notifications.');
    }

    public function test_manager_can_create_notifications(): void
    {
        $this->postJson('/api/notifications', [
            'recipient_user_id' => 1,
            'channel' => 'email',
            'type' => 'booking.confirmed',
            'subject' => 'Booking confirmed',
            'body' => 'Your booking is confirmed.',
        ], [
            'X-Test-User-Id' => '2',
            'X-Test-Roles' => 'HOTEL_MANAGER',
        ])->assertCreated();
    }
}
