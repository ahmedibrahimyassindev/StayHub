<?php

namespace App\Http\Controllers;

use App\Models\NotificationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipient_user_id' => ['sometimes', 'integer', 'min:1'],
            'channel' => ['sometimes', Rule::in($this->channels())],
            'type' => ['sometimes', 'string', 'max:80'],
            'status' => ['sometimes', Rule::in($this->statuses())],
            'unread' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $notifications = NotificationMessage::query()
            ->when($validated['recipient_user_id'] ?? null, fn ($query, $userId) => $query->where('recipient_user_id', $userId))
            ->when($validated['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(array_key_exists('unread', $validated), fn ($query) => $validated['unread'] ? $query->whereNull('read_at') : $query->whereNotNull('read_at'))
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($notifications);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateNotification($request);

        if (isset($validated['source_event_id'])) {
            $existingNotification = NotificationMessage::query()
                ->where('source_event_id', $validated['source_event_id'])
                ->first();

            if ($existingNotification !== null) {
                return response()->json([
                    'data' => $existingNotification,
                    'meta' => [
                        'idempotent_replay' => true,
                    ],
                ]);
            }
        }

        $notification = NotificationMessage::query()->create($validated);

        return response()->json([
            'data' => $notification->refresh(),
            'meta' => [
                'idempotent_replay' => false,
            ],
        ], 201);
    }

    public function show(NotificationMessage $notification): JsonResponse
    {
        return response()->json([
            'data' => $notification,
        ]);
    }

    public function send(NotificationMessage $notification): JsonResponse
    {
        if ($notification->status !== NotificationMessage::STATUS_QUEUED) {
            throw ValidationException::withMessages([
                'status' => 'Only queued notifications can be sent.',
            ]);
        }

        $notification->update([
            'status' => NotificationMessage::STATUS_SENT,
            'failure_reason' => null,
            'sent_at' => now(),
        ]);

        return response()->json([
            'data' => $notification->refresh(),
        ]);
    }

    public function fail(Request $request, NotificationMessage $notification): JsonResponse
    {
        if ($notification->status !== NotificationMessage::STATUS_QUEUED) {
            throw ValidationException::withMessages([
                'status' => 'Only queued notifications can be failed.',
            ]);
        }

        $validated = $request->validate([
            'failure_reason' => ['sometimes', 'string', 'max:255'],
        ]);

        $notification->update([
            'status' => NotificationMessage::STATUS_FAILED,
            'failure_reason' => $validated['failure_reason'] ?? 'Mock delivery failure.',
        ]);

        return response()->json([
            'data' => $notification->refresh(),
        ]);
    }

    public function markRead(NotificationMessage $notification): JsonResponse
    {
        $notification->update([
            'read_at' => $notification->read_at ?? now(),
        ]);

        return response()->json([
            'data' => $notification->refresh(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateNotification(Request $request): array
    {
        return $request->validate([
            'source_event_id' => ['sometimes', 'uuid'],
            'recipient_user_id' => ['required', 'integer', 'min:1'],
            'channel' => ['required', Rule::in($this->channels())],
            'type' => ['required', 'string', 'max:80'],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string'],
            'payload' => ['sometimes', 'array'],
            'status' => ['sometimes', Rule::in([NotificationMessage::STATUS_QUEUED])],
        ]);
    }

    /**
     * @return list<string>
     */
    private function channels(): array
    {
        return ['email', 'sms', 'in_app'];
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return [
            NotificationMessage::STATUS_QUEUED,
            NotificationMessage::STATUS_SENT,
            NotificationMessage::STATUS_FAILED,
        ];
    }
}
