<?php

namespace App\Http\Controllers;

use App\Models\RoomInventory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomInventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $inventory = RoomInventory::query()
            ->when($validated['room_id'] ?? null, fn ($query, $roomId) => $query->where('room_id', $roomId))
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date))
            ->orderBy('room_id')
            ->orderBy('date')
            ->paginate($validated['per_page'] ?? 30);

        return response()->json($inventory);
    }

    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date'],
            'total_rooms' => ['required', 'integer', 'min:0'],
            'available_rooms' => ['required', 'integer', 'min:0'],
            'reserved_rooms' => ['sometimes', 'integer', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $reservedRooms = $validated['reserved_rooms'] ?? 0;

        if ($validated['available_rooms'] + $reservedRooms > $validated['total_rooms']) {
            throw ValidationException::withMessages([
                'available_rooms' => 'Available plus reserved rooms cannot exceed total rooms.',
            ]);
        }

        $inventory = RoomInventory::query()->updateOrCreate(
            [
                'room_id' => $validated['room_id'],
                'date' => $validated['date'],
            ],
            [
                'total_rooms' => $validated['total_rooms'],
                'available_rooms' => $validated['available_rooms'],
                'reserved_rooms' => $reservedRooms,
                'price' => $validated['price'],
                'currency' => strtoupper($validated['currency']),
            ],
        );

        return response()->json([
            'data' => $inventory,
        ]);
    }

    public function reserve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'min:1'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $quantity = $validated['quantity'] ?? 1;
        $dates = $this->nightsBetween($validated['check_in'], $validated['check_out']);

        try {
            $pricing = $this->pricingForStay($validated['room_id'], $dates, $quantity);

            DB::transaction(function () use ($validated, $quantity, $dates) {
                foreach ($dates as $date) {
                    $updated = RoomInventory::query()
                        ->where('room_id', $validated['room_id'])
                        ->whereDate('date', $date)
                        ->where('available_rooms', '>=', $quantity)
                        ->update([
                            'available_rooms' => DB::raw("available_rooms - {$quantity}"),
                            'reserved_rooms' => DB::raw("reserved_rooms + {$quantity}"),
                            'updated_at' => now(),
                        ]);

                    if ($updated !== 1) {
                        throw new InventoryUnavailableException($date);
                    }
                }
            });
        } catch (InventoryUnavailableException $exception) {
            return response()->json([
                'message' => 'Room inventory is not available for the requested stay.',
                'failed_date' => $exception->date,
            ], 409);
        }

        return response()->json([
            'data' => [
                'room_id' => $validated['room_id'],
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'quantity' => $quantity,
                'nights_reserved' => count($dates),
                'total_amount' => $pricing['total_amount'],
                'currency' => $pricing['currency'],
            ],
        ]);
    }

    public function release(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'min:1'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $quantity = $validated['quantity'] ?? 1;
        $dates = $this->nightsBetween($validated['check_in'], $validated['check_out']);

        DB::transaction(function () use ($validated, $quantity, $dates) {
            foreach ($dates as $date) {
                RoomInventory::query()
                    ->where('room_id', $validated['room_id'])
                    ->whereDate('date', $date)
                    ->where('reserved_rooms', '>=', $quantity)
                    ->update([
                        'available_rooms' => DB::raw("available_rooms + {$quantity}"),
                        'reserved_rooms' => DB::raw("reserved_rooms - {$quantity}"),
                        'updated_at' => now(),
                    ]);
            }
        });

        return response()->json([
            'data' => [
                'room_id' => $validated['room_id'],
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'quantity' => $quantity,
                'nights_released' => count($dates),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function nightsBetween(string $checkIn, string $checkOut): array
    {
        $current = CarbonImmutable::parse($checkIn)->startOfDay();
        $end = CarbonImmutable::parse($checkOut)->startOfDay();
        $dates = [];

        while ($current->lt($end)) {
            $dates[] = $current->toDateString();
            $current = $current->addDay();
        }

        return $dates;
    }

    /**
     * @param list<string> $dates
     * @return array{total_amount: string, currency: string}
     */
    private function pricingForStay(int $roomId, array $dates, int $quantity): array
    {
        $inventoryRows = RoomInventory::query()
            ->where('room_id', $roomId)
            ->whereIn('date', $dates)
            ->get(['date', 'price', 'currency']);

        if ($inventoryRows->count() !== count($dates)) {
            $coveredDates = $inventoryRows
                ->map(fn (RoomInventory $inventory) => $inventory->date->toDateString())
                ->all();
            $missingDate = collect($dates)->first(fn (string $date) => ! in_array($date, $coveredDates, true));

            throw new InventoryUnavailableException($missingDate ?? $dates[0]);
        }

        $currency = strtoupper((string) $inventoryRows->first()->currency);

        if ($inventoryRows->contains(fn (RoomInventory $inventory) => strtoupper($inventory->currency) !== $currency)) {
            throw ValidationException::withMessages([
                'currency' => 'Inventory currency must be consistent for the full stay.',
            ]);
        }

        $totalAmount = $inventoryRows->sum(fn (RoomInventory $inventory) => (float) $inventory->price) * $quantity;

        return [
            'total_amount' => number_format($totalAmount, 2, '.', ''),
            'currency' => $currency,
        ];
    }
}

class InventoryUnavailableException extends \RuntimeException
{
    public function __construct(public readonly string $date)
    {
        parent::__construct("Inventory unavailable for {$date}");
    }
}
