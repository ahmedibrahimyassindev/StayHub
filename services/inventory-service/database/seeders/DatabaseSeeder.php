<?php

namespace Database\Seeders;

use App\Models\RoomInventory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $startDate = CarbonImmutable::now()->addDay();

        foreach ([1, 2, 3] as $roomId) {
            for ($offset = 0; $offset < 30; $offset++) {
                $date = $startDate->addDays($offset)->toDateString();
                $totalRooms = match ($roomId) {
                    1 => 10,
                    2 => 4,
                    default => 6,
                };

                RoomInventory::query()->updateOrCreate(
                    [
                        'room_id' => $roomId,
                        'date' => $date,
                    ],
                    [
                        'total_rooms' => $totalRooms,
                        'available_rooms' => $totalRooms,
                        'reserved_rooms' => 0,
                        'price' => match ($roomId) {
                            1 => 180.00,
                            2 => 320.00,
                            default => 260.00,
                        },
                        'currency' => 'USD',
                    ],
                );
            }
        }
    }
}
