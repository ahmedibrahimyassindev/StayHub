<?php

namespace App\Models;

use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'hotel_id',
    'room_id',
    'check_in',
    'check_out',
    'quantity',
    'status',
    'total_amount',
    'currency',
    'payment_id',
    'idempotency_key',
    'saga_id',
    'saga_state',
    'saga_error',
    'compensated_at',
    'cancelled_at',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PAYMENT_FAILED = 'payment_failed';

    public const SAGA_AWAITING_PAYMENT = 'awaiting_payment';
    public const SAGA_COMPLETED = 'completed';
    public const SAGA_COMPENSATED = 'compensated';
    public const SAGA_COMPENSATION_FAILED = 'compensation_failed';

    protected function casts(): array
    {
        return [
            'check_in' => 'date:Y-m-d',
            'check_out' => 'date:Y-m-d',
            'quantity' => 'integer',
            'total_amount' => 'decimal:2',
            'compensated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
