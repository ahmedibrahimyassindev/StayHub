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
            'status' => ['sometimes', Rule::in([Booking::STATUS_CONFIRMED, Booking::STATUS_CANCELLED])],
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
                'status' => Booking::STATUS_CONFIRMED,
                'currency' => strtoupper($validated['currency']),
            ]);
        } catch (Throwable $exception) {
            $this->postInventory('/api/inventory/releases', $inventoryPayload);

            throw $exception;
        }

        return response()->json([
            'data' => $booking,
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

        return response()->json([
            'data' => $booking->refresh(),
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
}
