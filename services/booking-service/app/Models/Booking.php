<?php

namespace App\Models;

use App\Enums\BookingSagaState;
use App\Enums\BookingStatus;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

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
    'request_hash',
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

    public const STATUS_PENDING_PAYMENT = BookingStatus::PendingPayment->value;
    public const STATUS_CONFIRMED = BookingStatus::Confirmed->value;
    public const STATUS_CANCELLED = BookingStatus::Cancelled->value;
    public const STATUS_PAYMENT_FAILED = BookingStatus::PaymentFailed->value;

    public const SAGA_AWAITING_PAYMENT = BookingSagaState::AwaitingPayment->value;
    public const SAGA_COMPLETED = BookingSagaState::Completed->value;
    public const SAGA_COMPENSATED = BookingSagaState::Compensated->value;
    public const SAGA_COMPENSATION_FAILED = BookingSagaState::CompensationFailed->value;

    protected function casts(): array
    {
        return [
            'check_in' => 'date:Y-m-d',
            'check_out' => 'date:Y-m-d',
            'quantity' => 'integer',
            'status' => BookingStatus::class,
            'saga_state' => BookingSagaState::class,
            'total_amount' => 'decimal:2',
            'compensated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function markConfirmed(): void
    {
        $this->ensureStatus(BookingStatus::PendingPayment, 'Only pending payment bookings can be confirmed.');

        $this->update([
            'status' => BookingStatus::Confirmed,
            'saga_state' => BookingSagaState::Completed,
        ]);
    }

    public function markCancelled(): void
    {
        if ($this->status === BookingStatus::Cancelled) {
            return;
        }

        if ($this->status === BookingStatus::PaymentFailed) {
            throw ValidationException::withMessages([
                'status' => 'Payment failed bookings cannot be cancelled.',
            ]);
        }

        $this->update([
            'status' => BookingStatus::Cancelled,
            'saga_state' => BookingSagaState::Compensated,
            'saga_error' => null,
            'compensated_at' => now(),
            'cancelled_at' => now(),
        ]);
    }

    public function markPaymentFailedCompensated(): void
    {
        $this->ensureStatus(BookingStatus::PendingPayment, 'Only pending payment bookings can be failed.');

        $this->update([
            'status' => BookingStatus::PaymentFailed,
            'saga_state' => BookingSagaState::Compensated,
            'saga_error' => null,
            'compensated_at' => now(),
        ]);
    }

    public function markCompensationFailed(string $message): void
    {
        $this->update([
            'saga_state' => BookingSagaState::CompensationFailed,
            'saga_error' => $message,
        ]);
    }

    public function markCompensationRecovered(): void
    {
        $this->update([
            'saga_state' => BookingSagaState::Compensated,
            'saga_error' => null,
            'compensated_at' => now(),
        ]);
    }

    private function ensureStatus(BookingStatus $status, string $message): void
    {
        if ($this->status !== $status) {
            throw ValidationException::withMessages([
                'status' => $message,
            ]);
        }
    }
}
