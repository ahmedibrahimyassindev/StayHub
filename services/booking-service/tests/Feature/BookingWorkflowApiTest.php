<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_creation_reserves_inventory_creates_payment_and_notification(): void
    {
        config([
            'services.inventory.url' => 'http://inventory-service:8000',
            'services.payment.url' => 'http://payment-service:8000',
            'services.notification.url' => 'http://notification-service:8000',
        ]);

        Http::fake([
            'inventory-service:8000/api/inventory/reservations' => Http::response([
                'data' => ['nights_reserved' => 1],
            ]),
            'payment-service:8000/api/payments' => Http::response([
                'data' => ['id' => 99, 'status' => 'pending'],
            ], 201),
            'notification-service:8000/api/notifications' => Http::response([
                'data' => ['id' => 100, 'type' => 'booking.pending_payment'],
            ], 201),
        ]);

        $this->postJson('/api/bookings', [
            'user_id' => 1,
            'hotel_id' => 1,
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
            'total_amount' => 180.00,
            'currency' => 'usd',
        ])->assertCreated()
            ->assertJsonPath('data.booking.status', Booking::STATUS_PENDING_PAYMENT)
            ->assertJsonPath('data.booking.payment_id', 99)
            ->assertJsonPath('data.notification.type', 'booking.pending_payment');

        $this->assertDatabaseHas('bookings', [
            'user_id' => 1,
            'payment_id' => 99,
            'status' => Booking::STATUS_PENDING_PAYMENT,
            'currency' => 'USD',
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
            'notification-service:8000/api/notifications' => Http::response([
                'data' => ['id' => 100, 'type' => 'payment.failed'],
            ], 201),
        ]);

        $this->postJson("/api/bookings/{$booking->id}/fail-payment", [
            'failure_reason' => 'Card declined',
        ])->assertOk()
            ->assertJsonPath('data.booking.status', Booking::STATUS_PAYMENT_FAILED)
            ->assertJsonPath('data.payment.status', 'failed')
            ->assertJsonPath('data.notification.type', 'payment.failed');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => Booking::STATUS_PAYMENT_FAILED,
        ]);

        Http::assertSentCount(3);
    }
}
