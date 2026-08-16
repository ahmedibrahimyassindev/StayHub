<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileApiTest extends TestCase
{
    use RefreshDatabase;

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
        ])->assertCreated()
            ->assertJsonPath('data.role', 'CUSTOMER')
            ->json('data.id');

        $this->putJson("/api/users/profiles/{$profileId}", [
            'phone' => '+201000000002',
        ])->assertOk()
            ->assertJsonPath('data.phone', '+201000000002');

        $this->getJson('/api/users/profiles/keycloak/customer')
            ->assertOk()
            ->assertJsonPath('data.id', $profileId);
    }
}
