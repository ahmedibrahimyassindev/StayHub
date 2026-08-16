<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_can_succeed_and_refund(): void
    {
        $paymentId = $this->postJson('/api/payments', [
            'booking_id' => 10,
            'user_id' => 1,
            'amount' => 210.00,
            'currency' => 'usd',
        ])->assertCreated()
            ->assertJsonPath('data.status', Payment::STATUS_PENDING)
            ->assertJsonPath('data.currency', 'USD')
            ->json('data.id');

        $this->postJson("/api/payments/{$paymentId}/succeed")
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_SUCCEEDED)
            ->assertJsonPath('data.failure_reason', null);

        $this->postJson("/api/payments/{$paymentId}/fail", [
            'failure_reason' => 'Card declined',
        ])->assertUnprocessable();

        $this->postJson("/api/payments/{$paymentId}/refund")
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_REFUNDED);
    }

    public function test_payment_creation_is_idempotent_for_same_user_and_key(): void
    {
        $payload = [
            'booking_id' => 10,
            'user_id' => 1,
            'amount' => 210.00,
            'currency' => 'usd',
        ];
        $headers = [
            'Idempotency-Key' => 'payment-create-1',
        ];

        $paymentId = $this->postJson('/api/payments', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', Payment::STATUS_PENDING)
            ->assertJsonPath('data.idempotency_key', 'payment-create-1')
            ->assertJsonPath('meta.idempotent_replay', false)
            ->json('data.id');

        $this->postJson('/api/payments', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $paymentId)
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payment_creation_rejects_same_idempotency_key_with_different_payload(): void
    {
        $headers = [
            'Idempotency-Key' => 'payment-create-conflict',
        ];

        $this->postJson('/api/payments', [
            'booking_id' => 10,
            'user_id' => 1,
            'amount' => 210.00,
            'currency' => 'usd',
        ], $headers)->assertCreated();

        $this->postJson('/api/payments', [
            'booking_id' => 10,
            'user_id' => 1,
            'amount' => 220.00,
            'currency' => 'usd',
        ], $headers)->assertConflict()
            ->assertJsonPath('message', 'Idempotency key was already used with a different request payload.');

        $this->assertDatabaseCount('payments', 1);
    }
}
