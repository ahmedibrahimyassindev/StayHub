<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HotelSearchApiTest extends TestCase
{
    public function test_search_returns_rooms_available_for_every_requested_night(): void
    {
        config([
            'services.hotel.url' => 'http://hotel-service:8000',
            'services.inventory.url' => 'http://inventory-service:8000',
        ]);

        Http::fake([
            'hotel-service:8000/api/hotels/1/rooms*' => Http::response([
                'data' => [
                    [
                        'id' => 10,
                        'hotel_id' => 1,
                        'name' => 'Deluxe Nile Double',
                        'capacity' => 2,
                        'base_price' => '180.00',
                        'status' => 'active',
                    ],
                ],
            ]),
            'hotel-service:8000/api/hotels*' => Http::response([
                'data' => [
                    [
                        'id' => 1,
                        'name' => 'Nile View Cairo',
                        'city' => 'Cairo',
                        'country' => 'Egypt',
                        'status' => 'active',
                    ],
                ],
            ]),
            'inventory-service:8000/api/inventory*' => Http::response([
                'data' => [
                    ['room_id' => 10, 'date' => '2026-09-01', 'available_rooms' => 1],
                    ['room_id' => 10, 'date' => '2026-09-02', 'available_rooms' => 1],
                ],
            ]),
        ]);

        $this->getJson('/api/search/hotels?city=Cairo&check_in=2026-09-01&check_out=2026-09-03&guests=2')
            ->assertOk()
            ->assertJsonPath('meta.hotels_available', 1)
            ->assertJsonPath('data.0.rooms.0.room.id', 10);
    }
}
