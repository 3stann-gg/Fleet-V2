<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminSeedPassword = config('services.admin_seed.password');

        if (! is_string($adminSeedPassword) || trim($adminSeedPassword) === '') {
            throw new RuntimeException(
                'ADMIN_SEED_PASSWORD must be set to a non-blank value before running AdminUserSeeder.'
            );
        }

        User::query()->forceCreate([
            'name' => 'System Administrator',
            'email' => 'himsfleet@gmail.com',
            'password' => Hash::make($adminSeedPassword),
            'role' => 'fleet_manager',
            'status' => true,
        ]);
    }
}
