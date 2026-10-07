<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateUser extends Command
{
    protected $signature = 'user:create {--username= : Login name} {--name= : Display name (defaults to the username)} {--password= : Password (asked for when left out)}';

    protected $description = 'Create an account that can log in, or set a new password for an existing one';

    public function handle(): int
    {
        $username = Str::lower(trim((string) ($this->option('username') ?? $this->ask('Username'))));
        $existing = User::where('username', $username)->first();
        $name = $this->option('name') ?? $existing?->name ?? $this->ask('Display name', $username);
        $password = $this->option('password') ?? $this->secret('Password (at least 8 characters)');

        $validator = Validator::make(compact('username', 'name', 'password'), [
            'username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ], ['username.regex' => 'The username may only contain letters, digits, dots, dashes and underscores.']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::updateOrCreate(['username' => $username], ['name' => $name, 'password' => $password]);
        $this->info($existing ? "Updated {$username}." : "Created {$username}. Log in at ".url('/login').'.');

        return self::SUCCESS;
    }
}
