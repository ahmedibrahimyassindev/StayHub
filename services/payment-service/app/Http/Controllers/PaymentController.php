<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['sometimes', 'integer', 'min:1'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in($this->statuses())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = Payment::query()
            ->when($validated['booking_id'] ?? null, fn ($query, $bookingId) => $query->where('booking_id', $bookingId))
            ->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'provider' => ['sometimes', 'string', 'max:40'],
        ]);

        $payment = Payment::query()->create([
            ...$validated,
            'currency' => strtoupper($validated['currency']),
            'provider' => $validated['provider'] ?? 'mock',
            'provider_reference' => 'mock_' . Str::uuid(),
            'status' => Payment::STATUS_PENDING,
        ]);

        return response()->json([
            'data' => $payment,
        ], 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json([
            'data' => $payment,
        ]);
    }

    public function succeed(Payment $payment): JsonResponse
    {
        $this->ensurePending($payment);

        $payment->update([
            'status' => Payment::STATUS_SUCCEEDED,
            'failure_reason' => null,
            'paid_at' => now(),
        ]);

        return response()->json([
            'data' => $payment->refresh(),
        ]);
    }

    public function fail(Request $request, Payment $payment): JsonResponse
    {
        $this->ensurePending($payment);

        $validated = $request->validate([
            'failure_reason' => ['sometimes', 'string', 'max:255'],
        ]);

        $payment->update([
            'status' => Payment::STATUS_FAILED,
            'failure_reason' => $validated['failure_reason'] ?? 'Payment failed in mock provider.',
        ]);

        return response()->json([
            'data' => $payment->refresh(),
        ]);
    }

    public function refund(Payment $payment): JsonResponse
    {
        if ($payment->status !== Payment::STATUS_SUCCEEDED) {
            throw ValidationException::withMessages([
                'status' => 'Only succeeded payments can be refunded.',
            ]);
        }

        $payment->update([
            'status' => Payment::STATUS_REFUNDED,
            'refunded_at' => now(),
        ]);

        return response()->json([
            'data' => $payment->refresh(),
        ]);
    }

    private function ensurePending(Payment $payment): void
    {
        if ($payment->status !== Payment::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Only pending payments can be changed by this operation.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return [
            Payment::STATUS_PENDING,
            Payment::STATUS_SUCCEEDED,
            Payment::STATUS_FAILED,
            Payment::STATUS_REFUNDED,
        ];
    }
}
