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
}
