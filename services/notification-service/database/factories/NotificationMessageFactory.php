<?php

namespace Database\Factories;

use App\Models\NotificationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationMessage>
 */
class NotificationMessageFactory extends Factory
{
    protected $model = NotificationMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient_user_id' => fake()->numberBetween(1, 20),
            'channel' => fake()->randomElement(['email', 'sms', 'in_app']),
            'type' => fake()->randomElement(['booking.created', 'payment.succeeded', 'payment.failed']),
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'payload' => [],
            'status' => NotificationMessage::STATUS_QUEUED,
        ];
    }
}
