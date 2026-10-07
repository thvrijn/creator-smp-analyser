<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * The default admin account. Change the password after the first login with `php artisan user:create`.
     * Safe to re-run: an existing account (and a password changed since) is kept.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['username' => 'thoompje'],
            ['name' => 'Thoompje', 'email' => User::ADMIN_EMAIL, 'password' => 'admin'],
        );
    }
}
