<?php

namespace Database\Seeders;

use App\Models\Hotel;
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
        Hotel::query()->firstOrCreate(
            ['slug' => 'nile-view-cairo'],
            [
                'name' => 'Nile View Cairo',
                'description' => 'Business-friendly hotel near central Cairo with Nile-facing rooms.',
                'country' => 'Egypt',
                'city' => 'Cairo',
                'address' => 'Corniche El Nile, Cairo',
                'latitude' => 30.0444196,
                'longitude' => 31.2357116,
                'rating' => 4.50,
                'status' => 'active',
            ],
        );

        Hotel::query()->firstOrCreate(
            ['slug' => 'alexandria-sea-house'],
            [
                'name' => 'Alexandria Sea House',
                'description' => 'Coastal hotel close to the Mediterranean promenade.',
                'country' => 'Egypt',
                'city' => 'Alexandria',
                'address' => 'El-Gaish Road, Alexandria',
                'latitude' => 31.2000924,
                'longitude' => 29.9187387,
                'rating' => 4.20,
                'status' => 'active',
            ],
        );

        Hotel::factory()->count(8)->create();
    }
}
