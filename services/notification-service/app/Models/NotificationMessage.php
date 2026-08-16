<?php

namespace App\Models;

use Database\Factories\NotificationMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source_event_id',
    'recipient_user_id',
    'channel',
    'type',
    'subject',
    'body',
    'payload',
    'status',
    'failure_reason',
    'sent_at',
    'read_at',
])]
class NotificationMessage extends Model
{
    /** @use HasFactory<NotificationMessageFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $table = 'notifications';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }
}
