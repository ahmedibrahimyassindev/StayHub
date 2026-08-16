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
}
