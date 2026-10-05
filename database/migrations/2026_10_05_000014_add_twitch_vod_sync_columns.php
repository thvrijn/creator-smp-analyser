<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->timestamp('vods_synced_at')->nullable()->after('twitch_login');
        });

        Schema::table('streams', function (Blueprint $table): void {
            // A synced Twitch VOD; unique so a re-sync never creates it twice.
            $table->string('twitch_video_id')->nullable()->unique()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->dropUnique(['twitch_video_id']);
            $table->dropColumn('twitch_video_id');
        });

        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn('vods_synced_at');
        });
    }
};
