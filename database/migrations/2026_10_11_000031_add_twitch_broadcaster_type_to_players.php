<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The channel's Twitch broadcaster type (partner, affiliate or none), filled by the VOD sync. It sets how long Twitch
    // keeps the channel's VODs (VodRetention). Null = not synced since this column was added.
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->string('twitch_broadcaster_type', 20)->nullable()->after('twitch_login');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn('twitch_broadcaster_type');
        });
    }
};
