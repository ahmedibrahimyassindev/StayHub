<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Http\Requests\BookingIndexRequest;
use App\Http\Requests\FailBookingPaymentRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Services\CreateBookingAction;
use App\Services\InventoryClient;
use App\Services\OutboxRecorder;
use App\Services\PaymentClient;
use App\Security\IdentityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function __construct(
        private readonly CreateBookingAction $createBooking,
        private readonly InventoryClient $inventory,
        private readonly PaymentClient $payment,
        private readonly OutboxRecorder $outbox,
        private readonly IdentityResolver $identityResolver,
    ) {
    }

    public function index(BookingIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $identity = $this->identityResolver->resolve($request);

        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        $bookings = Booking::query()
            ->when(! $identity->canManageBookings(), fn ($query) => $query->where('user_id', $identity->userId()))
            ->when($validated['hotel_id'] ?? null, fn ($query, $hotelId) => $query->where('hotel_id', $hotelId))
            ->when($validated['room_id'] ?? null, fn ($query, $roomId) => $query->where('room_id', $roomId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($bookings);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $identity = $this->identityResolver->resolve($request);

        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        $quantity = $validated['quantity'] ?? 1;
        $validated['quantity'] = $quantity;

        return $this->createBooking->execute(
            $validated,
            $identity->userId(),
            $request->headers->get('Idempotency-Key'),
        );
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $authorization = $this->identityResolver->authorizeBookingAccess($request, $booking->user_id);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        return response()->json([
            'data' => $booking,
        ]);
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $authorization = $this->identityResolver->authorizeBookingAccess($request, $booking->user_id);

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
        $authorization = $this->identityResolver->authorizeBookingAccess($request, $booking->user_id);

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

    public function failPayment(FailBookingPaymentRequest $request, Booking $booking): JsonResponse
    {
        $authorization = $this->identityResolver->authorizeBookingAccess($request, $booking->user_id);

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

        $validated = $request->validated();

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

}
