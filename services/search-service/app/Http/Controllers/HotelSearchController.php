<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class HotelSearchController extends Controller
{
    public function hotels(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'city' => ['sometimes', 'string', 'max:120'],
            'country' => ['sometimes', 'string', 'max:120'],
            'check_in' => ['required_with:check_out', 'date'],
            'check_out' => ['required_with:check_in', 'date', 'after:check_in'],
            'guests' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'max_price' => ['sometimes', 'numeric', 'min:0'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $hotels = $this->getHotels($validated);

        if ($hotels instanceof JsonResponse) {
            return $hotels;
        }

        $results = [];

        foreach ($hotels['data'] ?? [] as $hotel) {
            $rooms = $this->getRooms($hotel['id'], $validated);

            if ($rooms instanceof JsonResponse) {
                return $rooms;
            }

            $availableRooms = [];

            foreach ($rooms['data'] ?? [] as $room) {
                $availability = $this->getAvailability($room['id'], $validated);

                if ($availability instanceof JsonResponse) {
                    return $availability;
                }

                if (! $this->isAvailable($availability, $validated)) {
                    continue;
                }

                $availableRooms[] = [
                    'room' => $room,
                    'availability' => $availability['data'] ?? [],
                ];
            }

            if ($availableRooms !== []) {
                $results[] = [
                    'hotel' => $hotel,
                    'rooms' => $availableRooms,
                ];
            }
        }

        return response()->json([
            'data' => $results,
            'meta' => [
                'hotels_checked' => count($hotels['data'] ?? []),
                'hotels_available' => count($results),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function getHotels(array $filters): JsonResponse|array
    {
        return $this->getJson(config('services.hotel.url') . '/api/hotels', [
            'city' => $filters['city'] ?? null,
            'country' => $filters['country'] ?? null,
            'status' => 'active',
            'per_page' => $filters['per_page'] ?? 20,
        ], 'Hotel service is unavailable.', 'Hotel service rejected the search request.');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function getRooms(int $hotelId, array $filters): JsonResponse|array
    {
        return $this->getJson(config('services.hotel.url') . "/api/hotels/{$hotelId}/rooms", [
            'status' => 'active',
            'min_capacity' => $filters['guests'] ?? null,
            'max_price' => $filters['max_price'] ?? null,
            'per_page' => 100,
        ], 'Hotel service is unavailable.', 'Hotel service rejected the room search request.');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function getAvailability(int $roomId, array $filters): JsonResponse|array
    {
        $params = [
            'room_id' => $roomId,
            'per_page' => 100,
        ];

        if (isset($filters['check_in'], $filters['check_out'])) {
            $params['date_from'] = $filters['check_in'];
            $params['date_to'] = CarbonImmutable::parse($filters['check_out'])->subDay()->toDateString();
        }

        return $this->getJson(config('services.inventory.url') . '/api/inventory', $params, 'Inventory service is unavailable.', 'Inventory service rejected the availability request.');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function getJson(string $url, array $params, string $unavailableMessage, string $failedMessage): JsonResponse|array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->get($url, array_filter($params, fn ($value) => $value !== null));
        } catch (ConnectionException) {
            return response()->json([
                'message' => $unavailableMessage,
            ], 503);
        }

        if ($response->failed()) {
            return response()->json([
                'message' => $failedMessage,
                'upstream_status' => $response->status(),
                'upstream' => $response->json(),
            ], 502);
        }

        return $response->json() ?? [];
    }

    /**
     * @param array<string, mixed> $availability
     * @param array<string, mixed> $filters
     */
    private function isAvailable(array $availability, array $filters): bool
    {
        $dates = $availability['data'] ?? [];

        if (! isset($filters['check_in'], $filters['check_out'])) {
            return $dates !== [];
        }

        $expectedNights = (int) CarbonImmutable::parse($filters['check_in'])
            ->diffInDays(CarbonImmutable::parse($filters['check_out']));

        if (count($dates) !== $expectedNights) {
            return false;
        }

        foreach ($dates as $date) {
            if (($date['available_rooms'] ?? 0) < 1) {
                return false;
            }
        }

        return true;
    }
}
