<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'booking_id',
    'user_id',
    'amount',
    'currency',
    'status',
    'provider',
    'provider_reference',
    'idempotency_key',
    'failure_reason',
    'paid_at',
    'refunded_at',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const STATUS_PENDING = PaymentStatus::Pending->value;
    public const STATUS_SUCCEEDED = PaymentStatus::Succeeded->value;
    public const STATUS_FAILED = PaymentStatus::Failed->value;
    public const STATUS_REFUNDED = PaymentStatus::Refunded->value;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
