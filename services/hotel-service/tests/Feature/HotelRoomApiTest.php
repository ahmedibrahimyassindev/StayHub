<?php

namespace Tests\Feature;

use App\Models\Hotel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelRoomApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_hotel_and_room_can_be_created_and_listed(): void
    {
        $hotelId = $this->postJson('/api/hotels', [
            'name' => 'Nile View Cairo',
            'description' => 'Business hotel near the Nile.',
            'country' => 'Egypt',
            'city' => 'Cairo',
            'address' => 'Corniche El Nile',
            'rating' => 4.5,
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'nile-view-cairo')
            ->json('data.id');

        $this->postJson("/api/hotels/{$hotelId}/rooms", [
            'room_type' => 'double',
            'name' => 'Deluxe Nile Double',
            'description' => 'Double room with Nile view.',
            'capacity' => 2,
            'base_price' => 180.00,
            'currency' => 'USD',
            'amenities' => ['wifi', 'breakfast'],
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.hotel_id', $hotelId)
            ->assertJsonPath('data.room_type', 'double');

        $this->getJson("/api/hotels/{$hotelId}/rooms?status=active")
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->assertDatabaseHas('hotels', [
            'id' => $hotelId,
            'slug' => 'nile-view-cairo',
        ]);

        $this->assertSame(1, Hotel::query()->count());
    }
}
