<?php

namespace App\Security;

use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class UserAccess
{
    public function resolve(Request $request): JsonResponse|AuthenticatedIdentity
    {
        if (config('services.keycloak.allow_test_identity_headers')) {
            $userId = $request->headers->get('X-Test-User-Id');
            $username = (string) $request->headers->get('X-Test-Username', '');

            if (! is_numeric($userId) || (int) $userId < 1 || $username === '') {
                return $this->unauthenticated();
            }

            $roles = $request->headers->get('X-Test-Roles') ? explode(',', (string) $request->headers->get('X-Test-Roles')) : [];

            return new AuthenticatedIdentity((int) $userId, $username, $this->cleanRoles($roles));
        }

        $claims = $this->verifiedJwtClaims($request);

        if ($claims === null) {
            return $this->unauthenticated();
        }

        $userId = Arr::get($claims, 'stayhub_user_id') ?? Arr::get($claims, 'user_id');
        $username = (string) Arr::get($claims, 'preferred_username', '');

        if ($userId === null) {
            $userId = match ($username) {
                'customer' => 1,
                'manager' => 2,
                'admin' => 3,
                default => null,
            };
        }

        if (! is_numeric($userId) || (int) $userId < 1 || $username === '') {
            return $this->unauthenticated();
        }

        return new AuthenticatedIdentity((int) $userId, $username, $this->cleanRoles((array) Arr::get($claims, 'realm_access.roles', [])));
    }

    public function authorizeProfileRead(Request $request, UserProfile $profile): ?JsonResponse
    {
        $identity = $this->resolve($request);

        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        if (! $identity->canManageUsers() && $profile->keycloak_user_id !== $identity->username()) {
            return response()->json(['message' => 'You are not allowed to access this profile.'], 403);
        }

        return null;
    }

    public function authorizeKeycloakProfileRead(Request $request, string $keycloakUserId): ?JsonResponse
    {
        $identity = $this->resolve($request);

        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        if (! $identity->canManageUsers() && $keycloakUserId !== $identity->username()) {
            return response()->json(['message' => 'You are not allowed to access this profile.'], 403);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function authorizeProfileWrite(Request $request, array $profile, ?UserProfile $existingProfile = null): JsonResponse|AuthenticatedIdentity
    {
        $identity = $this->resolve($request);

        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        if ($identity->canManageUsers()) {
            return $identity;
        }

        $targetKeycloakId = (string) ($profile['keycloak_user_id'] ?? $existingProfile?->keycloak_user_id ?? '');

        if ($targetKeycloakId !== $identity->username()) {
            return response()->json(['message' => 'You are not allowed to manage this profile.'], 403);
        }

        if (($profile['role'] ?? $existingProfile?->role ?? 'CUSTOMER') !== 'CUSTOMER') {
            return response()->json(['message' => 'You are not allowed to assign elevated roles.'], 403);
        }

        return $identity;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verifiedJwtClaims(Request $request): ?array
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $header = $this->jsonDecodeSegment($parts[0]);
        $claims = $this->jsonDecodeSegment($parts[1]);

        if ($header === null || $claims === null || ($header['alg'] ?? null) !== 'RS256' || ! $this->claimsAreAllowed($claims)) {
            return null;
        }

        $jwk = $this->jwkForKey((string) ($header['kid'] ?? ''));
        $signature = $this->base64UrlDecode($parts[2]);
        $publicKey = $jwk === null ? null : $this->rsaPublicKeyPem($jwk);

        if ($signature === false || $publicKey === null) {
            return null;
        }

        return openssl_verify("{$parts[0]}.{$parts[1]}", $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1 ? $claims : null;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function claimsAreAllowed(array $claims): bool
    {
        $issuer = config('services.keycloak.issuer');
        $audience = config('services.keycloak.audience');

        if ($issuer && ($claims['iss'] ?? null) !== $issuer) {
            return false;
        }

        if (($claims['exp'] ?? 0) < time()) {
            return false;
        }

        return in_array($audience, (array) ($claims['aud'] ?? []), true) || ($claims['azp'] ?? null) === $audience;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jwkForKey(string $keyId): ?array
    {
        if ($keyId === '') {
            return null;
        }

        try {
            $keys = Cache::remember('keycloak.jwks', 300, function () {
                $response = Http::timeout(5)->get(config('services.keycloak.jwks_url'));

                return $response->successful() ? ($response->json('keys') ?? []) : [];
            });
        } catch (Throwable) {
            return null;
        }

        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $keyId && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonDecodeSegment(string $segment): ?array
    {
        $decoded = $this->base64UrlDecode($segment);

        if ($decoded === false) {
            return null;
        }

        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    private function base64UrlDecode(string $value): string|false
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);

        return base64_decode($value, true);
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function rsaPublicKeyPem(array $jwk): ?string
    {
        if (! isset($jwk['n'], $jwk['e'])) {
            return null;
        }

        $modulus = $this->base64UrlDecode((string) $jwk['n']);
        $exponent = $this->base64UrlDecode((string) $jwk['e']);

        if ($modulus === false || $exponent === false) {
            return null;
        }

        $sequence = $this->asn1Sequence($this->asn1Integer($modulus) . $this->asn1Integer($exponent));
        $publicKey = $this->asn1Sequence(
            $this->asn1Sequence($this->asn1ObjectIdentifier("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00")
            . $this->asn1BitString("\x00" . $sequence)
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($publicKey), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $encoded = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private function asn1Integer(string $value): string
    {
        if (ord($value[0]) > 0x7f) {
            $value = "\x00" . $value;
        }

        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Sequence(string $value): string
    {
        return "\x30" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1ObjectIdentifier(string $value): string
    {
        return "\x06" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1BitString(string $value): string
    {
        return "\x03" . $this->asn1Length(strlen($value)) . $value;
    }

    /**
     * @param array<int, string> $roles
     * @return list<string>
     */
    private function cleanRoles(array $roles): array
    {
        return array_values(array_filter(array_map(fn ($role) => trim((string) $role), $roles)));
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['message' => 'Authenticated user identity is required.'], 401);
    }
}
