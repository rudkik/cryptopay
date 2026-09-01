<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The bootstrap admin account, from ADMIN_EMAIL / ADMIN_PASSWORD.
 *
 * Idempotent: the password is only written when the user is first created, so
 * restarting the container never resets a password changed since.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@cryptopay.local');
        $password = (string) env('ADMIN_PASSWORD', 'password');

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Administrator',
                'password' => Hash::make($password),
                'role' => UserRole::Admin->value,
                'is_active' => true,
            ],
        );

        if ($user->wasRecentlyCreated) {
            $this->command?->info("Admin user created: {$email}");
        }
    }
}
