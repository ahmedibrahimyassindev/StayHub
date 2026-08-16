<?php

namespace App\Services;

use App\Models\Booking;
use Throwable;
use Illuminate\Support\Facades\Http;

class NotificationClient
{
    public function create(Booking $booking, string $type, string $subject, string $body): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->post(rtrim(config('services.notification.url'), '/') . '/api/notifications', [
                    'recipient_user_id' => $booking->user_id,
                    'channel' => 'email',
                    'type' => $type,
                    'subject' => $subject,
                    'body' => $body,
                    'payload' => [
                        'booking_id' => $booking->id,
                        'hotel_id' => $booking->hotel_id,
                        'room_id' => $booking->room_id,
                        'payment_id' => $booking->payment_id,
                        'status' => $booking->status,
                    ],
                ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        return $response->json('data');
    }
}
