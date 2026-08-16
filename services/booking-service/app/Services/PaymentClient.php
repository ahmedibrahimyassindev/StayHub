<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class PaymentClient
{
    /**
     * @param array<string, mixed> $payload
     */
    public function post(string $path, array $payload, ?string $idempotencyKey = null): JsonResponse|array
    {
        try {
            $request = Http::acceptJson()->timeout(5);

            if ($idempotencyKey !== null) {
                $request = $request->withHeaders([
                    'Idempotency-Key' => $idempotencyKey,
                ]);
            }

            $response = $request->post(rtrim(config('services.payment.url'), '/') . $path, $payload);
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'Payment service is unavailable.',
            ], 503);
        }

        if ($response->failed()) {
            return response()->json([
                'message' => 'Payment service rejected the request.',
                'payment_status' => $response->status(),
                'payment' => $response->json(),
            ], 502);
        }

        return $response->json() ?? [];
    }
}
