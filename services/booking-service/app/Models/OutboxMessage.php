<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'event_id',
    'topic',
    'type',
    'aggregate_type',
    'aggregate_id',
    'correlation_id',
    'payload',
    'headers',
    'status',
    'attempts',
    'available_at',
    'published_at',
    'last_error',
])]
class OutboxMessage extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OutboxMessage $message): void {
            $message->available_at ??= now();
        });
    }
}
