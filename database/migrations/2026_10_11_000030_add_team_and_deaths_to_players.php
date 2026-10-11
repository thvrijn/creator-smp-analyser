<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The player's team in the game (Noord or Zuid, CreatorSmpTeamSeeder), and their deaths on the server
    // (CreatorSmpDeathSeeder): when, and the game's death message. source_id is the id on creatorsmp.nl.
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->string('team')->nullable()->after('twitch_login');
        });

        Schema::create('player_deaths', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('died_at');
            $table->string('message');
            $table->string('source_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['player_id', 'died_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_deaths');
        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn('team');
        });
    }
};
