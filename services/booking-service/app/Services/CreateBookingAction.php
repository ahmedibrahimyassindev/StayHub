<?php

namespace App\Services;

use App\Enums\BookingSagaState;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CreateBookingAction
{
    public function __construct(
        private readonly InventoryClient $inventory,
        private readonly PaymentClient $payment,
        private readonly OutboxRecorder $outbox,
    ) {
    }

    /**
     * @param array<string, mixed> $bookingData
     */
    public function execute(array $bookingData, int $userId, ?string $idempotencyKey): JsonResponse
    {
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        if ($idempotencyKey !== null) {
            $existingBooking = Booking::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingBooking !== null) {
                return response()->json([
                    'data' => [
                        'booking' => $existingBooking,
                        'payment' => null,
                        'notification' => null,
                    ],
                    'meta' => [
                        'idempotent_replay' => true,
                    ],
                ]);
            }
        }

        $quantity = $bookingData['quantity'] ?? 1;
        $inventoryPayload = $this->inventoryPayload($bookingData, $quantity);
        $reservation = $this->inventory->post('/api/inventory/reservations', $inventoryPayload);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        if (! isset($reservation['data']['total_amount'], $reservation['data']['currency'])) {
            $this->inventory->post('/api/inventory/releases', $inventoryPayload);

            return response()->json([
                'message' => 'Inventory service did not return authoritative pricing.',
            ], 502);
        }

        try {
            $booking = DB::transaction(function () use ($bookingData, $idempotencyKey, $quantity, $reservation, $userId) {
                $booking = Booking::query()->create([
                    'user_id' => $userId,
                    'hotel_id' => $bookingData['hotel_id'],
                    'room_id' => $bookingData['room_id'],
                    'check_in' => $bookingData['check_in'],
                    'check_out' => $bookingData['check_out'],
                    'quantity' => $quantity,
                    'status' => BookingStatus::PendingPayment,
                    'total_amount' => $reservation['data']['total_amount'],
                    'currency' => strtoupper($reservation['data']['currency']),
                    'idempotency_key' => $idempotencyKey,
                    'saga_id' => (string) Str::uuid(),
                    'saga_state' => BookingSagaState::AwaitingPayment,
                ]);

                $this->outbox->recordBookingEvent($booking, 'booking.created');

                return $booking;
            });
        } catch (Throwable $exception) {
            $this->inventory->post('/api/inventory/releases', $inventoryPayload);

            if ($idempotencyKey !== null) {
                $existingBooking = Booking::query()
                    ->where('user_id', $userId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingBooking !== null) {
                    return response()->json([
                        'data' => [
                            'booking' => $existingBooking,
                            'payment' => null,
                            'notification' => null,
                        ],
                        'meta' => [
                            'idempotent_replay' => true,
                        ],
                    ]);
                }
            }

            throw $exception;
        }

        $payment = $this->payment->post('/api/payments', [
            'booking_id' => $booking->id,
            'user_id' => $booking->user_id,
            'amount' => $booking->total_amount,
            'currency' => $booking->currency,
            'provider' => 'mock',
        ], $idempotencyKey === null ? null : "booking:{$booking->id}:payment");

        if ($payment instanceof JsonResponse) {
            $release = $this->inventory->post('/api/inventory/releases', $inventoryPayload);

            DB::transaction(function () use ($booking, $release) {
                $compensationFailed = $release instanceof JsonResponse;

                $booking->update([
                    'status' => BookingStatus::PaymentFailed,
                    'saga_state' => $compensationFailed ? BookingSagaState::CompensationFailed : BookingSagaState::Compensated,
                    'saga_error' => $compensationFailed ? 'Inventory release compensation failed after payment creation failure.' : null,
                    'compensated_at' => $compensationFailed ? null : now(),
                ]);

                $booking = $booking->refresh();
                $this->outbox->recordBookingEvent(
                    $booking,
                    $compensationFailed ? 'booking.compensation_failed' : 'booking.payment_failed',
                    [
                        'compensation' => $compensationFailed ? 'inventory_release_failed' : 'inventory_released',
                    ],
                );
            });

            return $payment;
        }

        $notificationEvent = DB::transaction(function () use ($booking, $payment) {
            $booking->update([
                'payment_id' => $payment['data']['id'] ?? null,
            ]);

            $booking = $booking->refresh();
            $this->outbox->recordBookingEvent($booking, 'payment.pending');

            return $this->outbox->recordNotificationRequested(
                $booking,
                'booking.pending_payment',
                'Your StayHub booking is pending payment',
                'Complete payment to confirm your booking.',
            );
        });

        return response()->json([
            'data' => [
                'booking' => $booking->refresh(),
                'payment' => $payment['data'] ?? $payment,
                'notification' => null,
                'notification_event' => [
                    'event_id' => $notificationEvent->event_id,
                    'topic' => $notificationEvent->topic,
                    'type' => $notificationEvent->type,
                    'status' => $notificationEvent->status,
                ],
            ],
            'meta' => [
                'idempotent_replay' => false,
            ],
        ], 201);
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private function inventoryPayload(array $booking, int $quantity): array
    {
        return [
            'room_id' => $booking['room_id'],
            'check_in' => $booking['check_in'],
            'check_out' => $booking['check_out'],
            'quantity' => $quantity,
        ];
    }

    private function normalizeIdempotencyKey(?string $idempotencyKey): ?string
    {
        $idempotencyKey = trim((string) $idempotencyKey);

        return $idempotencyKey === '' ? null : $idempotencyKey;
    }
}
