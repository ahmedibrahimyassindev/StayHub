<?php

namespace App\Security;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class InventoryAccess
{
    public function requireManager(Request $request): ?JsonResponse
    {
        if ($this->isServiceRequest($request)) {
            return null;
        }

        if (config('services.keycloak.allow_test_identity_headers')) {
            $roles = $request->headers->get('X-Test-Roles') ? explode(',', (string) $request->headers->get('X-Test-Roles')) : [];

            return $this->hasManagerRole($roles) ? null : $this->forbidden();
        }

        $claims = $this->jwtClaims($request);
        $roles = (array) Arr::get($claims, 'realm_access.roles', []);

        return $this->hasManagerRole($roles) ? null : $this->forbidden();
    }

    public function requireService(Request $request): ?JsonResponse
    {
        return $this->isServiceRequest($request)
            ? null
            : response()->json(['message' => 'Internal service token is required.'], 403);
    }

    private function isServiceRequest(Request $request): bool
    {
        $expected = (string) (config('services.internal.token') ?: getenv('INTERNAL_SERVICE_TOKEN') ?: ($_SERVER['INTERNAL_SERVICE_TOKEN'] ?? ''));
        $simpleHeader = (string) $request->headers->get('X-Service-Token', '');
        $stayHubHeader = (string) $request->headers->get('X-StayHub-Service-Token', '');
        $authorization = (string) $request->headers->get('Authorization', '');

        return $expected !== ''
            && (
                hash_equals($expected, $simpleHeader)
                || hash_equals($expected, $stayHubHeader)
                || hash_equals("Service {$expected}", $authorization)
            );
    }

    /**
     * @param array<int, string> $roles
     */
    private function hasManagerRole(array $roles): bool
    {
        return count(array_intersect($roles, ['ADMIN', 'HOTEL_MANAGER', 'admin', 'manager'])) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function jwtClaims(Request $request): array
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return [];
        }

        $parts = explode('.', $token);

        if (count($parts) < 2) {
            return [];
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return [];
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : [];
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'Manager or admin role is required.',
        ], 403);
    }
}
