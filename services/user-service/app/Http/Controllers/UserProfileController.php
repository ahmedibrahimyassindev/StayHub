<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['sometimes', Rule::in($this->roles())],
            'email' => ['sometimes', 'string', 'max:255'],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $profiles = UserProfile::query()
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

        $profile = UserProfile::query()->create($validated);

        return response()->json([
            'data' => $profile,
        ], 201);
    }

    public function show(UserProfile $profile): JsonResponse
    {
        return response()->json([
            'data' => $profile,
        ]);
    }

    public function showByKeycloakId(string $keycloakUserId): JsonResponse
    {
        $profile = UserProfile::query()
            ->where('keycloak_user_id', $keycloakUserId)
            ->firstOrFail();

        return response()->json([
            'data' => $profile,
        ]);
    }

    public function update(Request $request, UserProfile $profile): JsonResponse
    {
        $profile->update($this->validateProfile($request, $profile));

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
