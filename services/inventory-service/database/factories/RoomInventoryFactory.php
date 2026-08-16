<?php

namespace Database\Factories;

use App\Models\RoomInventory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomInventory>
 */
class RoomInventoryFactory extends Factory
{
    protected $model = RoomInventory::class;

    public function definition(): array
    {
        $totalRooms = fake()->numberBetween(5, 30);
        $reservedRooms = fake()->numberBetween(0, $totalRooms - 1);

        return [
            'room_id' => fake()->numberBetween(1, 10),
            'date' => fake()->dateTimeBetween('now', '+90 days')->format('Y-m-d'),
            'total_rooms' => $totalRooms,
            'available_rooms' => $totalRooms - $reservedRooms,
            'reserved_rooms' => $reservedRooms,
            'price' => fake()->randomFloat(2, 80, 500),
            'currency' => fake()->randomElement(['USD', 'EUR', 'EGP']),
        ];
    }
}

