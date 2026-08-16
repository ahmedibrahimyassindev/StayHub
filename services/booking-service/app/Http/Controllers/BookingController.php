<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Throwable;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hotel_id' => ['sometimes', 'integer', 'min:1'],
            'room_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in($this->statuses())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $identity = $this->identity($request);

        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        $bookings = Booking::query()
            ->when(! $identity['can_manage'], fn ($query) => $query->where('user_id', $identity['user_id']))
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
            'hotel_id' => ['required', 'integer', 'min:1'],
            'room_id' => ['required', 'integer', 'min:1'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $identity = $this->identity($request);

        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        $quantity = $validated['quantity'] ?? 1;
        $inventoryPayload = $this->inventoryPayload($validated, $quantity);
        $reservation = $this->postInventory('/api/inventory/reservations', $inventoryPayload);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        if (! isset($reservation['data']['total_amount'], $reservation['data']['currency'])) {
            $this->postInventory('/api/inventory/releases', $inventoryPayload);

            return response()->json([
                'message' => 'Inventory service did not return authoritative pricing.',
            ], 502);
        }

        try {
            $booking = Booking::query()->create([
                'user_id' => $identity['user_id'],
                'hotel_id' => $validated['hotel_id'],
                'room_id' => $validated['room_id'],
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'quantity' => $quantity,
                'status' => Booking::STATUS_PENDING_PAYMENT,
                'total_amount' => $reservation['data']['total_amount'],
                'currency' => strtoupper($reservation['data']['currency']),
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

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $authorization = $this->authorizeBookingAccess($request, $booking);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        return response()->json([
            'data' => $booking,
        ]);
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $authorization = $this->authorizeBookingAccess($request, $booking);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

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

    public function confirmPayment(Request $request, Booking $booking): JsonResponse
    {
        $authorization = $this->authorizeBookingAccess($request, $booking);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

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
        $authorization = $this->authorizeBookingAccess($request, $booking);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

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
     * @return JsonResponse|array{user_id: int, roles: list<string>, can_manage: bool}
     */
    private function identity(Request $request): JsonResponse|array
    {
        $userId = $request->headers->get('X-StayHub-User-Id')
            ?? $request->headers->get('X-User-Id')
            ?? Arr::get($this->jwtClaims($request), 'stayhub_user_id')
            ?? Arr::get($this->jwtClaims($request), 'user_id');

        if ($userId === null) {
            $username = Arr::get($this->jwtClaims($request), 'preferred_username');
            $userId = match ($username) {
                'customer' => 1,
                'manager' => 2,
                'admin' => 3,
                default => null,
            };
        }

        if (! is_numeric($userId) || (int) $userId < 1) {
            return response()->json([
                'message' => 'Authenticated user identity is required.',
            ], 401);
        }

        $rolesHeader = $request->headers->get('X-StayHub-Roles') ?? $request->headers->get('X-User-Roles');
        $roles = $rolesHeader
            ? array_map('trim', explode(',', $rolesHeader))
            : (array) Arr::get($this->jwtClaims($request), 'realm_access.roles', []);

        return [
            'user_id' => (int) $userId,
            'roles' => array_values(array_filter($roles)),
            'can_manage' => count(array_intersect($roles, ['admin', 'manager'])) > 0,
        ];
    }

    private function authorizeBookingAccess(Request $request, Booking $booking): ?JsonResponse
    {
        $identity = $this->identity($request);

        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        if (! $identity['can_manage'] && $booking->user_id !== $identity['user_id']) {
            return response()->json([
                'message' => 'You are not allowed to access this booking.',
            ], 403);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jwtClaims(Request $request): array
    {
        $authorization = $request->bearerToken();

        if ($authorization === null) {
            return [];
        }

        $parts = explode('.', $authorization);

        if (count($parts) < 2) {
            return [];
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return [];
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : [];
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
