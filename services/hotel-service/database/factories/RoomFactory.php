<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        $roomType = fake()->randomElement(['single', 'double', 'suite', 'family']);

        return [
            'hotel_id' => Hotel::factory(),
            'room_type' => $roomType,
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'capacity' => match ($roomType) {
                'single' => 1,
                'double' => 2,
                'suite' => fake()->numberBetween(2, 4),
                'family' => fake()->numberBetween(4, 6),
            },
            'base_price' => fake()->randomFloat(2, 60, 650),
            'currency' => fake()->randomElement(['USD', 'EUR', 'EGP']),
            'amenities' => fake()->randomElements([
                'wifi',
                'breakfast',
                'sea_view',
                'city_view',
                'workspace',
                'balcony',
                'mini_bar',
            ], fake()->numberBetween(2, 4)),
            'status' => fake()->randomElement(['draft', 'active', 'inactive']),
        ];
    }
}

