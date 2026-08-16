<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class InventoryClient
{
    /**
     * @param array<string, mixed> $payload
     */
    public function post(string $path, array $payload): JsonResponse|array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders($this->headers())
                ->timeout(5)
                ->post(rtrim(config('services.inventory.url'), '/') . $path, $payload);
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'Inventory service is unavailable.',
            ], 503);
        }

        if ($response->status() === 409) {
            return response()->json([
                'message' => 'Room inventory is not available for the requested stay.',
                'inventory' => $response->json(),
            ], 409);
        }

        if ($response->failed()) {
            return response()->json([
                'message' => 'Inventory service rejected the request.',
                'inventory_status' => $response->status(),
                'inventory' => $response->json(),
            ], 502);
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'X-Correlation-ID' => app()->bound('correlation_id') ? app('correlation_id') : '',
        ];

        $serviceToken = config('services.internal.token') ?: getenv('INTERNAL_SERVICE_TOKEN') ?: ($_SERVER['INTERNAL_SERVICE_TOKEN'] ?? null);

        if ($serviceToken) {
            $headers['X-StayHub-Service-Token'] = $serviceToken;
            $headers['X-Service-Token'] = $serviceToken;
            $headers['Authorization'] = 'Service ' . $serviceToken;
        }

        return $headers;
    }
}
