<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The storyline of a stream (what happened in the game), written by the analysis next to the events: a summary of
    // every part of the stream in time order, one story of the whole stream, and the players the streamer dealt with.
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->text('story_summary')->nullable();
            $table->json('story_players')->nullable();
            $table->json('story_parts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->dropColumn(['story_summary', 'story_players', 'story_parts']);
        });
    }
};
