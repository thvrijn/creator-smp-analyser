<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResetAdmin extends Command
{
    protected $signature = 'user:reset-admin {--username= : New login name (default: admin)} {--password= : New password (default: admin, asked for in production)}';

    protected $description = 'Reset the admin account\'s login name and password (creates the account when it is missing)';

    public function handle(): int
    {
        $username = Str::lower(trim((string) ($this->option('username') ?? 'admin')));
        // The server is reachable from outside, so production never gets the default password.
        $password = $this->option('password') ?? (app()->isProduction() ? $this->secret('New password') : 'admin');

        if (! preg_match('/^[a-z0-9._-]+$/', $username) || strlen($username) > 50) {
            $this->error('The username may only contain letters, digits, dots, dashes and underscores (max 50).');

            return self::FAILURE;
        }
        if ((string) $password === '') {
            $this->error('The password may not be empty.');

            return self::FAILURE;
        }

        $admin = User::where('email', User::ADMIN_EMAIL)->first();
        if (! $admin) {
            (new AdminUserSeeder)->run();
            $admin = User::where('email', User::ADMIN_EMAIL)->firstOrFail();
        }
        if (User::where('username', $username)->whereKeyNot($admin->getKey())->exists()) {
            $this->error("The username {$username} is already used by another account.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($admin, $username, $password): void {
            $admin->forceFill(['username' => $username, 'password' => $password, 'remember_token' => Str::random(60)])->save();
            // Logs out every device that was still logged in with the old password.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $admin->getKey())->delete();
            }
        });

        $this->info("Admin account reset: log in as {$username} at ".url('/login').'.');

        return self::SUCCESS;
    }
}
