<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_HEADERS = [
        'X-StayHub-Service-Token' => 'test-internal-token',
        'X-Service-Token' => 'test-internal-token',
        'Authorization' => 'Service test-internal-token',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.internal.token' => 'test-internal-token',
            'services.keycloak.allow_test_identity_headers' => true,
        ]);
    }

    public function test_payment_can_succeed_and_refund(): void
    {
        $paymentId = $this->postJson('/api/payments', [
            'booking_id' => 10,
            'user_id' => 1,
            'amount' => 210.00,
            'currency' => 'usd',
        ], self::SERVICE_HEADERS)->assertCreated()
            ->assertJsonPath('data.status', Payment::STATUS_PENDING)
            ->assertJsonPath('data.currency', 'USD')
            ->json('data.id');

        $this->postJson("/api/payments/{$paymentId}/succeed", [], self::SERVICE_HEADERS)
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_SUCCEEDED)
            ->assertJsonPath('data.failure_reason', null);

        $this->postJson("/api/payments/{$paymentId}/fail", [
            'failure_reason' => 'Card declined',
        ], self::SERVICE_HEADERS)->assertUnprocessable();

        $this->postJson("/api/payments/{$paymentId}/refund", [], self::SERVICE_HEADERS)
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
            ...self::SERVICE_HEADERS,
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
            ...self::SERVICE_HEADERS,
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

    public function test_payment_creation_requires_internal_service_or_manager(): void
    {
        $this->postJson('/api/payments', [
            'booking_id' => 10,
            'user_id' => 1,
            'amount' => 210.00,
            'currency' => 'usd',
        ], [
            'X-Test-User-Id' => '1',
        ])->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to manage payments.');
    }

    public function test_customer_cannot_read_another_users_payment(): void
    {
        $payment = Payment::query()->create([
            'booking_id' => 10,
            'user_id' => 1,
            'amount' => 210.00,
            'currency' => 'USD',
            'status' => Payment::STATUS_PENDING,
            'provider' => 'mock',
            'provider_reference' => 'mock_test',
        ]);

        $this->getJson("/api/payments/{$payment->id}", [
            'X-Test-User-Id' => '2',
        ])->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to access this payment.');
    }

    public function test_customer_only_lists_own_payments(): void
    {
        Payment::query()->create([
            'booking_id' => 10,
            'user_id' => 1,
            'amount' => 210.00,
            'currency' => 'USD',
            'status' => Payment::STATUS_PENDING,
            'provider' => 'mock',
            'provider_reference' => 'mock_customer',
        ]);
        Payment::query()->create([
            'booking_id' => 11,
            'user_id' => 2,
            'amount' => 220.00,
            'currency' => 'USD',
            'status' => Payment::STATUS_PENDING,
            'provider' => 'mock',
            'provider_reference' => 'mock_other',
        ]);

        $this->getJson('/api/payments', [
            'X-Test-User-Id' => '1',
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', 1);
    }

    public function test_manager_can_manage_payments(): void
    {
        $this->postJson('/api/payments', [
            'booking_id' => 10,
            'user_id' => 1,
            'amount' => 210.00,
            'currency' => 'usd',
        ], [
            'X-Test-User-Id' => '2',
            'X-Test-Roles' => 'HOTEL_MANAGER',
        ])->assertCreated();
    }
}
