<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\Room;
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
        $cairoHotel = Hotel::query()->firstOrCreate(
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

        $alexandriaHotel = Hotel::query()->firstOrCreate(
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

        $this->seedRoom(
            $cairoHotel,
            'deluxe-nile-double',
            [
                'room_type' => 'double',
                'name' => 'Deluxe Nile Double',
                'description' => 'Double room with Nile view and workspace.',
                'capacity' => 2,
                'base_price' => 180.00,
                'currency' => 'USD',
                'amenities' => ['wifi', 'breakfast', 'workspace', 'city_view'],
                'status' => 'active',
            ],
        );

        $this->seedRoom(
            $cairoHotel,
            'executive-suite',
            [
                'room_type' => 'suite',
                'name' => 'Executive Suite',
                'description' => 'Suite with separate living space and premium amenities.',
                'capacity' => 3,
                'base_price' => 320.00,
                'currency' => 'USD',
                'amenities' => ['wifi', 'breakfast', 'workspace', 'mini_bar'],
                'status' => 'active',
            ],
        );

        $this->seedRoom(
            $alexandriaHotel,
            'sea-view-family',
            [
                'room_type' => 'family',
                'name' => 'Sea View Family',
                'description' => 'Family room overlooking the Mediterranean promenade.',
                'capacity' => 5,
                'base_price' => 260.00,
                'currency' => 'USD',
                'amenities' => ['wifi', 'breakfast', 'sea_view', 'balcony'],
                'status' => 'active',
            ],
        );

        if (Hotel::query()->count() < 10) {
            Hotel::factory()
                ->count(8)
                ->create()
                ->each(fn (Hotel $hotel) => Room::factory()->count(3)->create([
                    'hotel_id' => $hotel->id,
                ]));
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function seedRoom(Hotel $hotel, string $key, array $attributes): void
    {
        $hotel->rooms()->firstOrCreate(
            ['name' => $attributes['name']],
            $attributes + ['description' => "Seed room {$key}"],
        );
    }
}
