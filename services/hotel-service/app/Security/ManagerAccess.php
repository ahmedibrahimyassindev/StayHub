<?php

namespace App\Security;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ManagerAccess
{
    public function requireManager(Request $request): ?JsonResponse
    {
        if (config('services.keycloak.allow_test_identity_headers')) {
            $roles = $request->headers->get('X-Test-Roles') ? explode(',', (string) $request->headers->get('X-Test-Roles')) : [];

            return $this->hasManagerRole($roles) ? null : $this->forbidden();
        }

        $claims = $this->jwtClaims($request);
        $roles = (array) Arr::get($claims, 'realm_access.roles', []);

        return $this->hasManagerRole($roles) ? null : $this->forbidden();
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
