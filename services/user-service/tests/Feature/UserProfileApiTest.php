<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.keycloak.allow_test_identity_headers' => true]);
    }

    public function test_profile_can_be_created_updated_and_fetched_by_keycloak_id(): void
    {
        $profileId = $this->postJson('/api/users/profiles', [
            'keycloak_user_id' => 'customer',
            'email' => 'customer@stayhub.local',
            'first_name' => 'Demo',
            'last_name' => 'Customer',
            'phone' => '+201000000001',
            'role' => 'CUSTOMER',
            'locale' => 'en',
            'metadata' => ['source' => 'test'],
        ], [
            'X-Test-User-Id' => '1',
            'X-Test-Username' => 'customer',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'CUSTOMER')
            ->json('data.id');

        $this->putJson("/api/users/profiles/{$profileId}", [
            'phone' => '+201000000002',
        ], [
            'X-Test-User-Id' => '1',
            'X-Test-Username' => 'customer',
        ])->assertOk()
            ->assertJsonPath('data.phone', '+201000000002');

        $this->getJson('/api/users/profiles/keycloak/customer', [
            'X-Test-User-Id' => '1',
            'X-Test-Username' => 'customer',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $profileId);
    }

    public function test_customer_cannot_create_profile_for_another_keycloak_user(): void
    {
        $this->postJson('/api/users/profiles', [
            'keycloak_user_id' => 'manager',
            'email' => 'manager@stayhub.local',
            'first_name' => 'Demo',
            'last_name' => 'Manager',
            'role' => 'CUSTOMER',
        ], [
            'X-Test-User-Id' => '1',
            'X-Test-Username' => 'customer',
        ])->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to manage this profile.');
    }

    public function test_customer_cannot_assign_elevated_role(): void
    {
        $this->postJson('/api/users/profiles', [
            'keycloak_user_id' => 'customer',
            'email' => 'customer@stayhub.local',
            'first_name' => 'Demo',
            'last_name' => 'Customer',
            'role' => 'ADMIN',
        ], [
            'X-Test-User-Id' => '1',
            'X-Test-Username' => 'customer',
        ])->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to assign elevated roles.');
    }

    public function test_customer_only_lists_own_profile(): void
    {
        $this->postJson('/api/users/profiles', [
            'keycloak_user_id' => 'customer',
            'email' => 'customer@stayhub.local',
            'first_name' => 'Demo',
            'last_name' => 'Customer',
            'role' => 'CUSTOMER',
        ], [
            'X-Test-User-Id' => '1',
            'X-Test-Username' => 'customer',
        ])->assertCreated();

        $this->postJson('/api/users/profiles', [
            'keycloak_user_id' => 'manager',
            'email' => 'manager@stayhub.local',
            'first_name' => 'Demo',
            'last_name' => 'Manager',
            'role' => 'HOTEL_MANAGER',
        ], [
            'X-Test-User-Id' => '2',
            'X-Test-Username' => 'manager',
            'X-Test-Roles' => 'HOTEL_MANAGER',
        ])->assertCreated();

        $this->getJson('/api/users/profiles', [
            'X-Test-User-Id' => '1',
            'X-Test-Username' => 'customer',
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.keycloak_user_id', 'customer');
    }

    public function test_manager_can_manage_any_profile(): void
    {
        $this->postJson('/api/users/profiles', [
            'keycloak_user_id' => 'manager-created',
            'email' => 'manager-created@stayhub.local',
            'first_name' => 'Managed',
            'last_name' => 'User',
            'role' => 'HOTEL_MANAGER',
        ], [
            'X-Test-User-Id' => '2',
            'X-Test-Username' => 'manager',
            'X-Test-Roles' => 'HOTEL_MANAGER',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'HOTEL_MANAGER');
    }
}
