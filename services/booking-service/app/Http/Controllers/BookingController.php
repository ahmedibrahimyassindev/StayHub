<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Throwable;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'hotel_id' => ['sometimes', 'integer', 'min:1'],
            'room_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in($this->statuses())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $bookings = Booking::query()
            ->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($validated['hotel_id'] ?? null, fn ($query, $hotelId) => $query->where('hotel_id', $hotelId))
            ->when($validated['room_id'] ?? null, fn ($query, $roomId) => $query->where('room_id', $roomId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($bookings);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'hotel_id' => ['required', 'integer', 'min:1'],
            'room_id' => ['required', 'integer', 'min:1'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $quantity = $validated['quantity'] ?? 1;
        $inventoryPayload = $this->inventoryPayload($validated, $quantity);
        $reservation = $this->postInventory('/api/inventory/reservations', $inventoryPayload);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        try {
            $booking = Booking::query()->create([
                ...$validated,
                'quantity' => $quantity,
                'status' => Booking::STATUS_PENDING_PAYMENT,
                'currency' => strtoupper($validated['currency']),
            ]);
        } catch (Throwable $exception) {
            $this->postInventory('/api/inventory/releases', $inventoryPayload);

            throw $exception;
        }

        $payment = $this->postPayment('/api/payments', [
            'booking_id' => $booking->id,
            'user_id' => $booking->user_id,
            'amount' => $booking->total_amount,
            'currency' => $booking->currency,
            'provider' => 'mock',
        ]);

        if ($payment instanceof JsonResponse) {
            $this->postInventory('/api/inventory/releases', $inventoryPayload);
            $booking->update(['status' => Booking::STATUS_PAYMENT_FAILED]);

            return $payment;
        }

        $booking->update([
            'payment_id' => $payment['data']['id'] ?? null,
        ]);

        $notification = $this->createNotification(
            $booking->refresh(),
            'booking.pending_payment',
            'Your StayHub booking is pending payment',
            'Complete payment to confirm your booking.',
        );

        return response()->json([
            'data' => [
                'booking' => $booking,
                'payment' => $payment['data'] ?? $payment,
                'notification' => $notification,
            ],
        ], 201);
    }

    public function show(Booking $booking): JsonResponse
    {
        return response()->json([
            'data' => $booking,
        ]);
    }

    public function cancel(Booking $booking): JsonResponse
    {
        if ($booking->status === Booking::STATUS_CANCELLED) {
            return response()->json([
                'data' => $booking,
            ]);
        }

        $release = $this->postInventory('/api/inventory/releases', [
            'room_id' => $booking->room_id,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'quantity' => $booking->quantity,
        ]);

        if ($release instanceof JsonResponse) {
            return $release;
        }

        $booking->update([
            'status' => Booking::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $notification = $this->createNotification(
            $booking->refresh(),
            'booking.cancelled',
            'Your StayHub booking was cancelled',
            'Your reserved room inventory has been released.',
        );

        return response()->json([
            'data' => [
                'booking' => $booking,
                'notification' => $notification,
            ],
        ]);
    }

    public function confirmPayment(Booking $booking): JsonResponse
    {
        if ($booking->status === Booking::STATUS_CONFIRMED) {
            return response()->json([
                'data' => $booking,
            ]);
        }

        if ($booking->status !== Booking::STATUS_PENDING_PAYMENT) {
            return response()->json([
                'message' => 'Only pending payment bookings can be confirmed.',
            ], 422);
        }

        if ($booking->payment_id === null) {
            return response()->json([
                'message' => 'Booking does not have a payment to confirm.',
            ], 422);
        }

        $payment = $this->postPayment("/api/payments/{$booking->payment_id}/succeed", []);

        if ($payment instanceof JsonResponse) {
            return $payment;
        }

        $booking->update([
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $notification = $this->createNotification(
            $booking->refresh(),
            'booking.confirmed',
            'Your StayHub booking is confirmed',
            'Payment succeeded and your booking is confirmed.',
        );

        return response()->json([
            'data' => [
                'booking' => $booking,
                'payment' => $payment['data'] ?? $payment,
                'notification' => $notification,
            ],
        ]);
    }

    public function failPayment(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->status === Booking::STATUS_PAYMENT_FAILED) {
            return response()->json([
                'data' => $booking,
            ]);
        }

        if ($booking->status !== Booking::STATUS_PENDING_PAYMENT) {
            return response()->json([
                'message' => 'Only pending payment bookings can be failed.',
            ], 422);
        }

        $validated = $request->validate([
            'failure_reason' => ['sometimes', 'string', 'max:255'],
        ]);

        if ($booking->payment_id === null) {
            return response()->json([
                'message' => 'Booking does not have a payment to fail.',
            ], 422);
        }

        $payment = $this->postPayment("/api/payments/{$booking->payment_id}/fail", [
            'failure_reason' => $validated['failure_reason'] ?? 'Payment failed.',
        ]);

        if ($payment instanceof JsonResponse) {
            return $payment;
        }

        $release = $this->releaseBookingInventory($booking);

        if ($release instanceof JsonResponse) {
            return $release;
        }

        $booking->update([
            'status' => Booking::STATUS_PAYMENT_FAILED,
        ]);

        $notification = $this->createNotification(
            $booking->refresh(),
            'payment.failed',
            'Your StayHub payment failed',
            'Payment failed and your reserved room inventory has been released.',
        );

        return response()->json([
            'data' => [
                'booking' => $booking,
                'payment' => $payment['data'] ?? $payment,
                'notification' => $notification,
            ],
        ]);
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

    /**
     * @param array<string, mixed> $payload
     */
    private function postInventory(string $path, array $payload): JsonResponse|array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->post(rtrim(config('services.inventory.url'), '/') . $path, $payload);
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'Inventory service is unavailable.',
            ], 503);
        }

        if ($response->status() === 409) {
            return response()->json([
                'message' => 'Room inventory is not available for the requested stay.',
                'inventory' => $response->json(),
            ], 409);
        }

        if ($response->failed()) {
            return response()->json([
                'message' => 'Inventory service rejected the request.',
                'inventory_status' => $response->status(),
                'inventory' => $response->json(),
            ], 502);
        }

        return $response->json() ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postPayment(string $path, array $payload): JsonResponse|array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->post(rtrim(config('services.payment.url'), '/') . $path, $payload);
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'Payment service is unavailable.',
            ], 503);
        }

        if ($response->failed()) {
            return response()->json([
                'message' => 'Payment service rejected the request.',
                'payment_status' => $response->status(),
                'payment' => $response->json(),
            ], 502);
        }

        return $response->json() ?? [];
    }

    private function releaseBookingInventory(Booking $booking): JsonResponse|array
    {
        return $this->postInventory('/api/inventory/releases', [
            'room_id' => $booking->room_id,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'quantity' => $booking->quantity,
        ]);
    }

    private function createNotification(Booking $booking, string $type, string $subject, string $body): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->post(rtrim(config('services.notification.url'), '/') . '/api/notifications', [
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
                ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        return $response->json('data');
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return [
            Booking::STATUS_PENDING_PAYMENT,
            Booking::STATUS_CONFIRMED,
            Booking::STATUS_CANCELLED,
            Booking::STATUS_PAYMENT_FAILED,
        ];
    }
}
