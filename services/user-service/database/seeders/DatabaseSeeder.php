<?php

namespace Database\Seeders;

use App\Models\UserProfile;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $profiles = [
            [
                'keycloak_user_id' => 'customer',
                'email' => 'customer@stayhub.local',
                'first_name' => 'Demo',
                'last_name' => 'Customer',
                'role' => 'CUSTOMER',
            ],
            [
                'keycloak_user_id' => 'manager',
                'email' => 'manager@stayhub.local',
                'first_name' => 'Hotel',
                'last_name' => 'Manager',
                'role' => 'HOTEL_MANAGER',
            ],
            [
                'keycloak_user_id' => 'admin',
                'email' => 'admin@stayhub.local',
                'first_name' => 'Platform',
                'last_name' => 'Admin',
                'role' => 'ADMIN',
            ],
        ];

        foreach ($profiles as $profile) {
            UserProfile::query()->updateOrCreate(
                ['keycloak_user_id' => $profile['keycloak_user_id']],
                $profile + [
                    'locale' => 'en',
                    'metadata' => [],
                ],
            );
        }
    }
}
