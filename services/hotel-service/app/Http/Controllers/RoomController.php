<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        $validated = $request->validate([
            'room_type' => ['sometimes', 'string', 'max:80'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'inactive'])],
            'min_capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'max_price' => ['sometimes', 'numeric', 'min:0'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $rooms = $hotel->rooms()
            ->when($validated['room_type'] ?? null, fn ($query, $roomType) => $query->where('room_type', $roomType))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['min_capacity'] ?? null, fn ($query, $capacity) => $query->where('capacity', '>=', $capacity))
            ->when($validated['max_price'] ?? null, fn ($query, $price) => $query->where('base_price', '<=', $price))
            ->orderBy('base_price')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($rooms);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $room = $hotel->rooms()->create($this->validateRoom($request));

        return response()->json([
            'data' => $room,
        ], 201);
    }

    public function show(Hotel $hotel, Room $room): JsonResponse
    {
        $this->ensureRoomBelongsToHotel($hotel, $room);

        return response()->json([
            'data' => $room,
        ]);
    }

    public function update(Request $request, Hotel $hotel, Room $room): JsonResponse
    {
        $this->ensureRoomBelongsToHotel($hotel, $room);

        $room->update($this->validateRoom($request, $room));

        return response()->json([
            'data' => $room->refresh(),
        ]);
    }

    public function destroy(Hotel $hotel, Room $room): JsonResponse
    {
        $this->ensureRoomBelongsToHotel($hotel, $room);

        $room->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRoom(Request $request, ?Room $room = null): array
    {
        $required = $room ? 'sometimes' : 'required';

        return $request->validate([
            'room_type' => [$required, Rule::in(['single', 'double', 'suite', 'family'])],
            'name' => [$required, 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'capacity' => [$required, 'integer', 'min:1', 'max:50'],
            'base_price' => [$required, 'numeric', 'min:0'],
            'currency' => [$required, 'string', 'size:3'],
            'amenities' => ['sometimes', 'array'],
            'amenities.*' => ['string', 'max:80'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'inactive'])],
        ]);
    }

    private function ensureRoomBelongsToHotel(Hotel $hotel, Room $room): void
    {
        abort_unless($room->hotel_id === $hotel->id, 404);
    }
}

