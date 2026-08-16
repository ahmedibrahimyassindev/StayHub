<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Security\ManagerAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HotelController extends Controller
{
    public function __construct(
        private readonly ManagerAccess $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'city' => ['sometimes', 'string', 'max:120'],
            'country' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'inactive'])],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $hotels = Hotel::query()
            ->when($validated['city'] ?? null, fn ($query, $city) => $query->where('city', 'ilike', "%{$city}%"))
            ->when($validated['country'] ?? null, fn ($query, $country) => $query->where('country', 'ilike', "%{$country}%"))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['q'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%")
                        ->orWhere('address', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($hotels);
    }

    public function store(Request $request): JsonResponse
    {
        $authorization = $this->access->requireManager($request);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $validated = $this->validateHotel($request);
        $validated['slug'] ??= $this->uniqueSlug($validated['name']);

        $hotel = Hotel::create($validated);

        return response()->json([
            'data' => $hotel,
        ], 201);
    }

    public function show(Hotel $hotel): JsonResponse
    {
        return response()->json([
            'data' => $hotel,
        ]);
    }

    public function update(Request $request, Hotel $hotel): JsonResponse
    {
        $authorization = $this->access->requireManager($request);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $validated = $this->validateHotel($request, $hotel);

        if (isset($validated['name']) && ! isset($validated['slug'])) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $hotel);
        }

        $hotel->update($validated);

        return response()->json([
            'data' => $hotel->refresh(),
        ]);
    }

    public function destroy(Request $request, Hotel $hotel): JsonResponse
    {
        $authorization = $this->access->requireManager($request);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $hotel->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateHotel(Request $request, ?Hotel $hotel = null): array
    {
        $hotelId = $hotel?->id;
        $required = $hotel ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:180'],
            'slug' => [
                'sometimes',
                'string',
                'max:200',
                Rule::unique('hotels', 'slug')->ignore($hotelId),
            ],
            'description' => ['nullable', 'string'],
            'country' => [$required, 'string', 'max:120'],
            'city' => [$required, 'string', 'max:120'],
            'address' => [$required, 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'rating' => ['nullable', 'numeric', 'between:0,5'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'inactive'])],
        ]);
    }

    private function uniqueSlug(string $name, ?Hotel $hotel = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 2;

        while (Hotel::query()
            ->where('slug', $slug)
            ->when($hotel, fn ($query) => $query->whereKeyNot($hotel->id))
            ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
