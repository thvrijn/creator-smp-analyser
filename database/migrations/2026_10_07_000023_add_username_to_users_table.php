<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Users log in with a username (stored lowercase, so logging in ignores case); the e-mail address becomes optional.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique()->after('id');
            $table->string('email')->nullable()->change();
        });

        // Existing accounts get their lowercased name, with a number only when another account already has it.
        DB::table('users')->whereNull('username')->orderBy('id')->each(function (object $user): void {
            $base = preg_replace('/[^a-z0-9._-]/', '', strtolower($user->name)) ?: 'user';
            $username = $base;
            for ($number = 2; DB::table('users')->where('username', $username)->exists(); $number++) {
                $username = $base.$number;
            }
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
