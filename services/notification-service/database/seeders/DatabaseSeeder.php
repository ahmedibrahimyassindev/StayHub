<?php

namespace Database\Seeders;

use App\Models\NotificationMessage;
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
        NotificationMessage::query()->firstOrCreate([
            'recipient_user_id' => 1,
            'type' => 'booking.created',
            'subject' => 'Your StayHub booking is pending payment',
        ], [
            'channel' => 'email',
            'body' => 'Complete payment to confirm your booking.',
            'payload' => [
                'booking_id' => 1,
            ],
            'status' => NotificationMessage::STATUS_QUEUED,
        ]);
    }
}
