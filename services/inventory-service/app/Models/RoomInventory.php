<?php

namespace App\Models;

use Database\Factories\RoomInventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'room_id',
    'date',
    'total_rooms',
    'available_rooms',
    'reserved_rooms',
    'price',
    'currency',
])]
class RoomInventory extends Model
{
    /** @use HasFactory<RoomInventoryFactory> */
    use HasFactory;

    protected $table = 'room_inventory';

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'total_rooms' => 'integer',
            'available_rooms' => 'integer',
            'reserved_rooms' => 'integer',
            'price' => 'decimal:2',
        ];
    }
}

