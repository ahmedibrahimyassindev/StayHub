<?php

namespace Tests\Feature;

use App\Models\RoomInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_can_be_reserved_and_released(): void
    {
        RoomInventory::query()->create([
            'room_id' => 1,
            'date' => '2026-09-01',
            'total_rooms' => 2,
            'available_rooms' => 2,
            'reserved_rooms' => 0,
            'price' => 180.00,
            'currency' => 'USD',
        ]);

        $this->postJson('/api/inventory/reservations', [
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
        ])->assertOk()
            ->assertJsonPath('data.nights_reserved', 1);

        $this->assertDatabaseHas('room_inventory', [
            'room_id' => 1,
            'available_rooms' => 1,
            'reserved_rooms' => 1,
        ]);

        $this->postJson('/api/inventory/releases', [
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
        ])->assertOk()
            ->assertJsonPath('data.nights_released', 1);

        $this->assertDatabaseHas('room_inventory', [
            'room_id' => 1,
            'available_rooms' => 2,
            'reserved_rooms' => 0,
        ]);
    }

    public function test_reservation_returns_conflict_when_inventory_is_unavailable(): void
    {
        RoomInventory::query()->create([
            'room_id' => 1,
            'date' => '2026-09-01',
            'total_rooms' => 1,
            'available_rooms' => 0,
            'reserved_rooms' => 1,
            'price' => 180.00,
            'currency' => 'USD',
        ]);

        $this->postJson('/api/inventory/reservations', [
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
        ])->assertConflict()
            ->assertJsonPath('failed_date', '2026-09-01');
    }
}
