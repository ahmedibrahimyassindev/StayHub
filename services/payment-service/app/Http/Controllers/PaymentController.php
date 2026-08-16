<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Security\AuthenticatedIdentity;
use App\Security\PaymentAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentAccess $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['sometimes', 'integer', 'min:1'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(PaymentStatus::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $authorization = $this->access->authorizeIndex($request, isset($validated['user_id']) ? (int) $validated['user_id'] : null);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $identity = $authorization instanceof AuthenticatedIdentity ? $authorization : null;

        $payments = Payment::query()
            ->when($validated['booking_id'] ?? null, fn ($query, $bookingId) => $query->where('booking_id', $bookingId))
            ->when($identity !== null && ! $identity->canManagePayments(), fn ($query) => $query->where('user_id', $identity->userId()))
            ->when(($identity === null || $identity->canManagePayments()) && ($validated['user_id'] ?? null), fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $authorization = $this->access->requireServiceOrManager($request);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'provider' => ['sometimes', 'string', 'max:40'],
        ]);

        $idempotencyKey = $this->normalizeIdempotencyKey($request->headers->get('Idempotency-Key'));
        $requestHash = $this->requestHash($validated);

        if ($idempotencyKey !== null) {
            $existingPayment = Payment::query()
                ->where('user_id', $validated['user_id'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingPayment !== null) {
                if ($existingPayment->request_hash !== null && $existingPayment->request_hash !== $requestHash) {
                    return $this->idempotencyConflict();
                }

                return response()->json([
                    'data' => $existingPayment,
                    'meta' => [
                        'idempotent_replay' => true,
                    ],
                ]);
            }
        }

        try {
            $payment = Payment::query()->create([
                ...$validated,
                'currency' => strtoupper($validated['currency']),
                'provider' => $validated['provider'] ?? 'mock',
                'provider_reference' => 'mock_' . Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => PaymentStatus::Pending,
            ]);
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null) {
                $existingPayment = Payment::query()
                    ->where('user_id', $validated['user_id'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingPayment !== null) {
                    if ($existingPayment->request_hash !== null && $existingPayment->request_hash !== $requestHash) {
                        return $this->idempotencyConflict();
                    }

                    return response()->json([
                        'data' => $existingPayment,
                        'meta' => [
                            'idempotent_replay' => true,
                        ],
                    ]);
                }
            }

            throw $exception;
        }

        return response()->json([
            'data' => $payment,
            'meta' => [
                'idempotent_replay' => false,
            ],
        ], 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $authorization = $this->access->authorizePaymentRead($request, $payment);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        return response()->json([
            'data' => $payment,
        ]);
    }

    public function succeed(Request $request, Payment $payment): JsonResponse
    {
        $authorization = $this->access->requireServiceOrManager($request);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $this->ensurePending($payment);

        $payment->update([
            'status' => PaymentStatus::Succeeded,
            'failure_reason' => null,
            'paid_at' => now(),
        ]);

        return response()->json([
            'data' => $payment->refresh(),
        ]);
    }

    public function fail(Request $request, Payment $payment): JsonResponse
    {
        $authorization = $this->access->requireServiceOrManager($request);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $this->ensurePending($payment);

        $validated = $request->validate([
            'failure_reason' => ['sometimes', 'string', 'max:255'],
        ]);

        $payment->update([
            'status' => PaymentStatus::Failed,
            'failure_reason' => $validated['failure_reason'] ?? 'Payment failed in mock provider.',
        ]);

        return response()->json([
            'data' => $payment->refresh(),
        ]);
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $authorization = $this->access->requireServiceOrManager($request);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        if ($payment->status !== PaymentStatus::Succeeded) {
            throw ValidationException::withMessages([
                'status' => 'Only succeeded payments can be refunded.',
            ]);
        }

        $payment->update([
            'status' => PaymentStatus::Refunded,
            'refunded_at' => now(),
        ]);

        return response()->json([
            'data' => $payment->refresh(),
        ]);
    }

    private function ensurePending(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Only pending payments can be changed by this operation.',
            ]);
        }
    }

    private function normalizeIdempotencyKey(?string $idempotencyKey): ?string
    {
        $idempotencyKey = trim((string) $idempotencyKey);

        return $idempotencyKey === '' ? null : $idempotencyKey;
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function requestHash(array $payment): string
    {
        $fingerprint = [
            'booking_id' => (int) $payment['booking_id'],
            'user_id' => (int) $payment['user_id'],
            'amount' => number_format((float) $payment['amount'], 2, '.', ''),
            'currency' => strtoupper((string) $payment['currency']),
            'provider' => (string) ($payment['provider'] ?? 'mock'),
        ];

        return hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
    }

    private function idempotencyConflict(): JsonResponse
    {
        return response()->json([
            'message' => 'Idempotency key was already used with a different request payload.',
        ], 409);
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return [
            ...PaymentStatus::values(),
        ];
    }
}
