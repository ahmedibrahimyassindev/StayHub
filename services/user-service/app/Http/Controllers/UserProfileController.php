<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use App\Security\AuthenticatedIdentity;
use App\Security\UserAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    public function __construct(
        private readonly UserAccess $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['sometimes', Rule::in($this->roles())],
            'email' => ['sometimes', 'string', 'max:255'],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $identity = $this->access->resolve($request);

        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        $profiles = UserProfile::query()
            ->when(! $identity->canManageUsers(), fn ($query) => $query->where('keycloak_user_id', $identity->username()))
            ->when($validated['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($validated['email'] ?? null, fn ($query, $email) => $query->where('email', 'ilike', "%{$email}%"))
            ->when($validated['q'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('email', 'ilike', "%{$search}%")
                        ->orWhere('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('email')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($profiles);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateProfile($request);
        $authorization = $this->access->authorizeProfileWrite($request, $validated);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        if ($authorization instanceof AuthenticatedIdentity && ! $authorization->canManageUsers()) {
            $validated['keycloak_user_id'] = $authorization->username();
            $validated['role'] = 'CUSTOMER';
        }

        $profile = UserProfile::query()->create($validated);

        return response()->json([
            'data' => $profile,
        ], 201);
    }

    public function show(Request $request, UserProfile $profile): JsonResponse
    {
        $authorization = $this->access->authorizeProfileRead($request, $profile);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        return response()->json([
            'data' => $profile,
        ]);
    }

    public function showByKeycloakId(Request $request, string $keycloakUserId): JsonResponse
    {
        $authorization = $this->access->authorizeKeycloakProfileRead($request, $keycloakUserId);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $profile = UserProfile::query()
            ->where('keycloak_user_id', $keycloakUserId)
            ->firstOrFail();

        return response()->json([
            'data' => $profile,
        ]);
    }

    public function update(Request $request, UserProfile $profile): JsonResponse
    {
        $validated = $this->validateProfile($request, $profile);
        $authorization = $this->access->authorizeProfileWrite($request, $validated, $profile);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        if ($authorization instanceof AuthenticatedIdentity && ! $authorization->canManageUsers()) {
            unset($validated['keycloak_user_id'], $validated['role']);
        }

        $profile->update($validated);

        return response()->json([
            'data' => $profile->refresh(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProfile(Request $request, ?UserProfile $profile = null): array
    {
        $profileId = $profile?->id;
        $required = $profile ? 'sometimes' : 'required';

        return $request->validate([
            'keycloak_user_id' => [
                $required,
                'string',
                'max:120',
                Rule::unique('user_profiles', 'keycloak_user_id')->ignore($profileId),
            ],
            'email' => [
                $required,
                'email',
                'max:255',
                Rule::unique('user_profiles', 'email')->ignore($profileId),
            ],
            'first_name' => [$required, 'string', 'max:120'],
            'last_name' => [$required, 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => [$required, Rule::in($this->roles())],
            'locale' => ['sometimes', 'string', 'max:12'],
            'metadata' => ['sometimes', 'array'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function roles(): array
    {
        return ['CUSTOMER', 'HOTEL_MANAGER', 'ADMIN'];
    }
}
