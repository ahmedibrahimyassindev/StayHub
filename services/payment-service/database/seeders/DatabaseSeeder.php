<?php

namespace Database\Seeders;

use App\Models\Payment;
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
        Payment::query()->firstOrCreate([
            'provider_reference' => 'mock_seed_payment_1',
        ], [
            'booking_id' => 1,
            'user_id' => 1,
            'amount' => 210.00,
            'currency' => 'USD',
            'status' => Payment::STATUS_SUCCEEDED,
            'provider' => 'mock',
            'paid_at' => now(),
        ]);
    }
}
