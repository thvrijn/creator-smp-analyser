<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * The default admin account. Change the password after the first login on /profile, or with
     * `php artisan user:reset-admin` when it is forgotten.
     * Safe to re-run: an existing admin (found by e-mail, so also after a new username or password) is kept.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => User::ADMIN_EMAIL],
            ['username' => 'thoompje', 'name' => 'Thoompje', 'password' => 'admin'],
        );
    }
}
