<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\CreateBookingAction;
use App\Services\InventoryClient;
use App\Services\OutboxRecorder;
use App\Services\PaymentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    public function __construct(
        private readonly CreateBookingAction $createBooking,
        private readonly InventoryClient $inventory,
        private readonly PaymentClient $payment,
        private readonly OutboxRecorder $outbox,
    ) {
    }

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
        $validated['quantity'] = $quantity;

        return $this->createBooking->execute(
            $validated,
            $identity['user_id'],
            $request->headers->get('Idempotency-Key'),
        );
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

        if ($booking->status === BookingStatus::Cancelled) {
            return response()->json([
                'data' => $booking,
            ]);
        }

        $release = $this->inventory->post('/api/inventory/releases', [
            'room_id' => $booking->room_id,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'quantity' => $booking->quantity,
        ]);

        if ($release instanceof JsonResponse) {
            return $release;
        }

        $notificationEvent = DB::transaction(function () use ($booking) {
            $booking->markCancelled();

            $booking = $booking->refresh();
            $this->outbox->recordBookingEvent($booking, 'booking.cancelled', [
                'compensation' => 'inventory_released',
            ]);

            return $this->outbox->recordNotificationRequested(
                $booking,
                'booking.cancelled',
                'Your StayHub booking was cancelled',
                'Your reserved room inventory has been released.',
            );
        });

        return response()->json([
            'data' => [
                'booking' => $booking->refresh(),
                'notification' => null,
                'notification_event' => $this->notificationEventPayload($notificationEvent),
            ],
        ]);
    }

    public function confirmPayment(Request $request, Booking $booking): JsonResponse
    {
        $authorization = $this->authorizeBookingAccess($request, $booking);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        if ($booking->status === BookingStatus::Confirmed) {
            return response()->json([
                'data' => $booking,
            ]);
        }

        if ($booking->status !== BookingStatus::PendingPayment) {
            return response()->json([
                'message' => 'Only pending payment bookings can be confirmed.',
            ], 422);
        }

        if ($booking->payment_id === null) {
            return response()->json([
                'message' => 'Booking does not have a payment to confirm.',
            ], 422);
        }

        $payment = $this->payment->post("/api/payments/{$booking->payment_id}/succeed", []);

        if ($payment instanceof JsonResponse) {
            return $payment;
        }

        $notificationEvent = DB::transaction(function () use ($booking) {
            $booking->markConfirmed();

            $booking = $booking->refresh();
            $this->outbox->recordBookingEvent($booking, 'booking.confirmed');

            return $this->outbox->recordNotificationRequested(
                $booking,
                'booking.confirmed',
                'Your StayHub booking is confirmed',
                'Payment succeeded and your booking is confirmed.',
            );
        });

        return response()->json([
            'data' => [
                'booking' => $booking->refresh(),
                'payment' => $payment['data'] ?? $payment,
                'notification' => null,
                'notification_event' => $this->notificationEventPayload($notificationEvent),
            ],
        ]);
    }

    public function failPayment(Request $request, Booking $booking): JsonResponse
    {
        $authorization = $this->authorizeBookingAccess($request, $booking);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        if ($booking->status === BookingStatus::PaymentFailed) {
            return response()->json([
                'data' => $booking,
            ]);
        }

        if ($booking->status !== BookingStatus::PendingPayment) {
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

        $payment = $this->payment->post("/api/payments/{$booking->payment_id}/fail", [
            'failure_reason' => $validated['failure_reason'] ?? 'Payment failed.',
        ]);

        if ($payment instanceof JsonResponse) {
            return $payment;
        }

        $release = $this->releaseBookingInventory($booking);

        if ($release instanceof JsonResponse) {
            DB::transaction(function () use ($booking) {
                $booking->markCompensationFailed('Inventory release compensation failed after payment failure.');

                $this->outbox->recordBookingEvent($booking->refresh(), 'booking.compensation_failed', [
                    'compensation' => 'inventory_release_failed',
                ]);
            });

            return $release;
        }

        $notificationEvent = DB::transaction(function () use ($booking) {
            $booking->markPaymentFailedCompensated();

            $booking = $booking->refresh();
            $this->outbox->recordBookingEvent($booking, 'booking.payment_failed', [
                'compensation' => 'inventory_released',
            ]);

            return $this->outbox->recordNotificationRequested(
                $booking,
                'payment.failed',
                'Your StayHub payment failed',
                'Payment failed and your reserved room inventory has been released.',
            );
        });

        return response()->json([
            'data' => [
                'booking' => $booking->refresh(),
                'payment' => $payment['data'] ?? $payment,
                'notification' => null,
                'notification_event' => $this->notificationEventPayload($notificationEvent),
            ],
        ]);
    }

    private function releaseBookingInventory(Booking $booking): JsonResponse|array
    {
        return $this->inventory->post('/api/inventory/releases', [
            'room_id' => $booking->room_id,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'quantity' => $booking->quantity,
        ]);
    }

    /**
     * @return array{event_id: string, topic: string, type: string, status: string}
     */
    private function notificationEventPayload(object $event): array
    {
        return [
            'event_id' => $event->event_id,
            'topic' => $event->topic,
            'type' => $event->type,
            'status' => $event->status,
        ];
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
            ...BookingStatus::values(),
        ];
    }
}
