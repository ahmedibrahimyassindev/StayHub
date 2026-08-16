<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => fake()->numberBetween(1, 20),
            'user_id' => fake()->numberBetween(1, 20),
            'amount' => fake()->randomFloat(2, 100, 1500),
            'currency' => 'USD',
            'status' => Payment::STATUS_PENDING,
            'provider' => 'mock',
            'provider_reference' => 'mock_' . Str::uuid(),
        ];
    }
}
