<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '8da4c069-f0f4-4c95-b8d2-46043f23c8d1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.keycloak.allow_test_identity_headers' => true]);
    }

    public function test_booking_creation_reserves_inventory_creates_payment_and_notification(): void
    {
        config([
            'services.inventory.url' => 'http://inventory-service:8000',
            'services.payment.url' => 'http://payment-service:8000',
            'services.notification.url' => 'http://notification-service:8000',
        ]);

        Http::fake([
            'inventory-service:8000/api/inventory/reservations' => Http::response([
                'data' => [
                    'nights_reserved' => 2,
                    'total_amount' => '360.00',
                    'currency' => 'USD',
                ],
            ]),
            'payment-service:8000/api/payments' => Http::response([
                'data' => ['id' => 99, 'status' => 'pending'],
            ], 201),
        ]);

        $this->postJson('/api/bookings', [
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-03',
            'quantity' => 1,
        ], [
            'X-Test-User-Id' => '1',
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertCreated()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertJsonPath('data.booking.status', Booking::STATUS_PENDING_PAYMENT)
            ->assertJsonPath('data.booking.payment_id', 99)
            ->assertJsonPath('data.booking.total_amount', '360.00')
            ->assertJsonPath('data.booking.currency', 'USD')
            ->assertJsonPath('data.notification', null)
            ->assertJsonPath('data.notification_event.topic', 'notification-events')
            ->assertJsonPath('data.notification_event.type', 'notification.requested')
            ->assertJsonPath('meta.idempotent_replay', false);

        $this->assertDatabaseHas('bookings', [
            'user_id' => 1,
            'payment_id' => 99,
            'status' => Booking::STATUS_PENDING_PAYMENT,
            'total_amount' => 360.00,
            'currency' => 'USD',
            'saga_state' => Booking::SAGA_AWAITING_PAYMENT,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'topic' => 'booking-events',
            'type' => 'booking.created',
            'status' => OutboxMessage::STATUS_PENDING,
            'correlation_id' => self::CORRELATION_ID,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'topic' => 'notification-events',
            'type' => 'notification.requested',
            'status' => OutboxMessage::STATUS_PENDING,
        ]);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'http://payment-service:8000/api/payments'
            && $request->header('X-Correlation-ID')[0] === self::CORRELATION_ID
            && $request['user_id'] === 1
            && $request['amount'] === '360.00'
            && $request['currency'] === 'USD');
    }

    public function test_booking_creation_is_idempotent_for_same_user_and_key(): void
    {
        config([
            'services.inventory.url' => 'http://inventory-service:8000',
            'services.payment.url' => 'http://payment-service:8000',
            'services.notification.url' => 'http://notification-service:8000',
        ]);

        Http::fake([
            'inventory-service:8000/api/inventory/reservations' => Http::response([
                'data' => [
                    'nights_reserved' => 1,
                    'total_amount' => '180.00',
                    'currency' => 'USD',
                ],
            ]),
            'payment-service:8000/api/payments' => Http::response([
                'data' => ['id' => 99, 'status' => 'pending'],
            ], 201),
        ]);

        $payload = [
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
        ];
        $headers = [
            'X-Test-User-Id' => '1',
            'Idempotency-Key' => 'booking-create-1',
        ];

        $bookingId = $this->postJson('/api/bookings', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('meta.idempotent_replay', false)
            ->json('data.booking.id');

        $this->postJson('/api/bookings', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.booking.id', $bookingId)
            ->assertJsonPath('data.booking.idempotency_key', 'booking-create-1')
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('outbox_messages', 3);
        Http::assertSentCount(2);
    }

    public function test_inventory_unavailable_does_not_create_booking_or_payment(): void
    {
        config([
            'services.inventory.url' => 'http://inventory-service:8000',
            'services.payment.url' => 'http://payment-service:8000',
        ]);

        Http::fake([
            'inventory-service:8000/api/inventory/reservations' => Http::response([
                'message' => 'Room inventory is not available for the requested stay.',
                'failed_date' => '2026-09-01',
            ], 409),
        ]);

        $this->postJson('/api/bookings', [
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
        ], [
            'X-Test-User-Id' => '1',
        ])->assertConflict();

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        Http::assertSentCount(1);
    }

    public function test_payment_service_failure_releases_inventory_and_marks_booking_failed(): void
    {
        config([
            'services.inventory.url' => 'http://inventory-service:8000',
            'services.payment.url' => 'http://payment-service:8000',
        ]);

        Http::fake([
            'inventory-service:8000/api/inventory/reservations' => Http::response([
                'data' => [
                    'nights_reserved' => 1,
                    'total_amount' => '180.00',
                    'currency' => 'USD',
                ],
            ]),
            'payment-service:8000/api/payments' => Http::response([
                'message' => 'Payment service rejected the request.',
            ], 502),
            'inventory-service:8000/api/inventory/releases' => Http::response([
                'data' => ['nights_released' => 1],
            ]),
        ]);

        $this->postJson('/api/bookings', [
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
        ], [
            'X-Test-User-Id' => '1',
        ])->assertStatus(502);

        $this->assertDatabaseHas('bookings', [
            'status' => Booking::STATUS_PAYMENT_FAILED,
            'saga_state' => Booking::SAGA_COMPENSATED,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'type' => 'booking.payment_failed',
        ]);
        Http::assertSentCount(3);
    }

    public function test_payment_failure_releases_inventory_and_marks_booking_failed(): void
    {
        config([
            'services.inventory.url' => 'http://inventory-service:8000',
            'services.payment.url' => 'http://payment-service:8000',
            'services.notification.url' => 'http://notification-service:8000',
        ]);

        $booking = Booking::query()->create([
            'user_id' => 1,
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
            'status' => Booking::STATUS_PENDING_PAYMENT,
            'total_amount' => 180.00,
            'currency' => 'USD',
            'payment_id' => 99,
        ]);

        Http::fake([
            'payment-service:8000/api/payments/99/fail' => Http::response([
                'data' => ['id' => 99, 'status' => 'failed'],
            ]),
            'inventory-service:8000/api/inventory/releases' => Http::response([
                'data' => ['nights_released' => 1],
            ]),
        ]);

        $this->postJson("/api/bookings/{$booking->id}/fail-payment", [
            'failure_reason' => 'Card declined',
        ], [
            'X-Test-User-Id' => '1',
        ])->assertOk()
            ->assertJsonPath('data.booking.status', Booking::STATUS_PAYMENT_FAILED)
            ->assertJsonPath('data.payment.status', 'failed')
            ->assertJsonPath('data.notification', null)
            ->assertJsonPath('data.notification_event.topic', 'notification-events');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => Booking::STATUS_PAYMENT_FAILED,
            'saga_state' => Booking::SAGA_COMPENSATED,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'topic' => 'notification-events',
            'type' => 'notification.requested',
            'status' => OutboxMessage::STATUS_PENDING,
        ]);

        Http::assertSentCount(2);
    }

    public function test_payment_failure_tracks_failed_inventory_release_compensation(): void
    {
        config([
            'services.inventory.url' => 'http://inventory-service:8000',
            'services.payment.url' => 'http://payment-service:8000',
        ]);

        $booking = Booking::query()->create([
            'user_id' => 1,
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
            'status' => Booking::STATUS_PENDING_PAYMENT,
            'total_amount' => 180.00,
            'currency' => 'USD',
            'payment_id' => 99,
            'saga_state' => Booking::SAGA_AWAITING_PAYMENT,
        ]);

        Http::fake([
            'payment-service:8000/api/payments/99/fail' => Http::response([
                'data' => ['id' => 99, 'status' => 'failed'],
            ]),
            'inventory-service:8000/api/inventory/releases' => Http::response([
                'message' => 'Inventory service rejected the request.',
            ], 502),
        ]);

        $this->postJson("/api/bookings/{$booking->id}/fail-payment", [
            'failure_reason' => 'Card declined',
        ], [
            'X-Test-User-Id' => '1',
        ])->assertStatus(502);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => Booking::STATUS_PENDING_PAYMENT,
            'saga_state' => Booking::SAGA_COMPENSATION_FAILED,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'type' => 'booking.compensation_failed',
        ]);
    }

    public function test_failed_compensation_can_be_retried(): void
    {
        config([
            'services.inventory.url' => 'http://inventory-service:8000',
        ]);

        $booking = Booking::query()->create([
            'user_id' => 1,
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
            'status' => Booking::STATUS_PAYMENT_FAILED,
            'total_amount' => 180.00,
            'currency' => 'USD',
            'payment_id' => 99,
            'saga_state' => Booking::SAGA_COMPENSATION_FAILED,
            'saga_error' => 'Inventory release compensation failed after payment failure.',
        ]);

        Http::fake([
            'inventory-service:8000/api/inventory/releases' => Http::response([
                'data' => ['nights_released' => 1],
            ]),
        ]);

        $this->artisan('saga:retry-compensations', ['--limit' => 10])
            ->assertSuccessful();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'saga_state' => Booking::SAGA_COMPENSATED,
            'saga_error' => null,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'type' => 'booking.compensation_recovered',
        ]);
    }

    public function test_booking_creation_requires_authenticated_identity(): void
    {
        $this->postJson('/api/bookings', [
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-03',
            'quantity' => 1,
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Authenticated user identity is required.');
    }

    public function test_spoofed_identity_headers_are_ignored_without_test_bypass(): void
    {
        config(['services.keycloak.allow_test_identity_headers' => false]);

        $this->postJson('/api/bookings', [
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-03',
            'quantity' => 1,
        ], [
            'X-StayHub-User-Id' => '1',
            'X-StayHub-Roles' => 'manager',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Authenticated user identity is required.');
    }

    public function test_customer_cannot_view_another_users_booking(): void
    {
        $booking = Booking::query()->create([
            'user_id' => 1,
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
            'status' => Booking::STATUS_PENDING_PAYMENT,
            'total_amount' => 180.00,
            'currency' => 'USD',
        ]);

        $this->getJson("/api/bookings/{$booking->id}", [
            'X-Test-User-Id' => '2',
        ])->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to access this booking.');
    }

    public function test_manager_can_view_any_booking(): void
    {
        $booking = Booking::query()->create([
            'user_id' => 1,
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
            'status' => Booking::STATUS_PENDING_PAYMENT,
            'total_amount' => 180.00,
            'currency' => 'USD',
        ]);

        $this->getJson("/api/bookings/{$booking->id}", [
            'X-Test-User-Id' => '2',
            'X-Test-Roles' => 'manager',
        ])->assertOk()
            ->assertJsonPath('data.id', $booking->id);
    }

    public function test_outbox_publish_command_marks_pending_events_published(): void
    {
        OutboxMessage::query()->create([
            'event_id' => '2f8f5ab5-75cb-4108-9f45-8abff6398c41',
            'topic' => 'booking-events',
            'type' => 'booking.created',
            'aggregate_type' => 'booking',
            'aggregate_id' => '1',
            'correlation_id' => 'f8fdbfc4-33f6-4bd6-8d2f-6b2f8eac6722',
            'payload' => [
                'event_id' => '2f8f5ab5-75cb-4108-9f45-8abff6398c41',
                'type' => 'booking.created',
                'version' => 1,
                'correlation_id' => 'f8fdbfc4-33f6-4bd6-8d2f-6b2f8eac6722',
                'aggregate_id' => '1',
                'occurred_at' => now()->toISOString(),
                'payload' => ['booking_id' => 1],
            ],
            'headers' => [
                'event_id' => '2f8f5ab5-75cb-4108-9f45-8abff6398c41',
                'type' => 'booking.created',
            ],
        ]);

        $this->artisan('outbox:publish', ['--limit' => 10])
            ->assertSuccessful();

        $this->assertDatabaseHas('outbox_messages', [
            'event_id' => '2f8f5ab5-75cb-4108-9f45-8abff6398c41',
            'status' => OutboxMessage::STATUS_PUBLISHED,
            'attempts' => 1,
        ]);
    }
}
