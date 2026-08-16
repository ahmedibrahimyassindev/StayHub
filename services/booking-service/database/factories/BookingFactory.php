<?php

namespace Database\Factories;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkIn = fake()->dateTimeBetween('+1 week', '+2 months');
        $checkOut = (clone $checkIn)->modify('+' . fake()->numberBetween(1, 5) . ' days');

        return [
            'user_id' => fake()->numberBetween(1, 20),
            'hotel_id' => fake()->numberBetween(1, 5),
            'room_id' => fake()->numberBetween(1, 20),
            'check_in' => $checkIn->format('Y-m-d'),
            'check_out' => $checkOut->format('Y-m-d'),
            'quantity' => fake()->numberBetween(1, 3),
            'status' => Booking::STATUS_CONFIRMED,
            'total_amount' => fake()->randomFloat(2, 100, 1500),
            'currency' => 'USD',
        ];
    }
}
