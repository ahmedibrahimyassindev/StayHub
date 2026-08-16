<?php

namespace Tests\Feature;

use App\Models\RoomInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_HEADERS = [
        'X-Service-Token' => 'test-internal-token',
    ];

    private const MANAGER_HEADERS = [
        'X-Test-User-Id' => '2',
        'X-Test-Roles' => 'HOTEL_MANAGER',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.internal.token' => 'test-internal-token',
            'services.keycloak.allow_test_identity_headers' => true,
        ]);
    }

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
        ], self::SERVICE_HEADERS)->assertOk()
            ->assertJsonPath('data.nights_reserved', 1)
            ->assertJsonPath('data.total_amount', '180.00')
            ->assertJsonPath('data.currency', 'USD');

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
        ], self::SERVICE_HEADERS)->assertOk()
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
        ], self::SERVICE_HEADERS)->assertConflict()
            ->assertJsonPath('failed_date', '2026-09-01');
    }

    public function test_reservation_returns_total_for_each_night_and_room_quantity(): void
    {
        foreach (['2026-09-01', '2026-09-02'] as $date) {
            RoomInventory::query()->create([
                'room_id' => 1,
                'date' => $date,
                'total_rooms' => 4,
                'available_rooms' => 4,
                'reserved_rooms' => 0,
                'price' => 150.00,
                'currency' => 'USD',
            ]);
        }

        $this->postJson('/api/inventory/reservations', [
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-03',
            'quantity' => 2,
        ], self::SERVICE_HEADERS)->assertOk()
            ->assertJsonPath('data.nights_reserved', 2)
            ->assertJsonPath('data.total_amount', '600.00')
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_second_reservation_returns_conflict_after_inventory_is_exhausted(): void
    {
        RoomInventory::query()->create([
            'room_id' => 1,
            'date' => '2026-09-01',
            'total_rooms' => 1,
            'available_rooms' => 1,
            'reserved_rooms' => 0,
            'price' => 180.00,
            'currency' => 'USD',
        ]);

        $payload = [
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
        ];

        $this->postJson('/api/inventory/reservations', $payload, self::SERVICE_HEADERS)->assertOk();
        $this->postJson('/api/inventory/reservations', $payload, self::SERVICE_HEADERS)
            ->assertConflict()
            ->assertJsonPath('failed_date', '2026-09-01');

        $this->assertDatabaseHas('room_inventory', [
            'room_id' => 1,
            'available_rooms' => 0,
            'reserved_rooms' => 1,
        ]);
    }

    public function test_inventory_upsert_requires_manager(): void
    {
        $payload = [
            'room_id' => 1,
            'date' => '2026-09-01',
            'total_rooms' => 2,
            'available_rooms' => 2,
            'reserved_rooms' => 0,
            'price' => 180.00,
            'currency' => 'USD',
        ];

        $this->putJson('/api/inventory', $payload, [
            'X-Test-User-Id' => '1',
            'X-Test-Roles' => 'CUSTOMER',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Manager or admin role is required.');

        $this->putJson('/api/inventory', $payload, self::MANAGER_HEADERS)
            ->assertOk()
            ->assertJsonPath('data.room_id', 1);
    }

    public function test_inventory_reservation_requires_internal_service_token(): void
    {
        $this->postJson('/api/inventory/reservations', [
            'room_id' => 1,
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'quantity' => 1,
        ])->assertForbidden()
            ->assertJsonPath('message', 'Internal service token is required.');
    }
}
