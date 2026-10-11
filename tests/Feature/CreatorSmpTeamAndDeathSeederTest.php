<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerDeath;
use Database\Seeders\CreatorSmpDeathSeeder;
use Database\Seeders\CreatorSmpPlayerSeeder;
use Database\Seeders\CreatorSmpTeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreatorSmpTeamAndDeathSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_player_gets_a_team_and_the_deaths_are_stored_once(): void
    {
        Storage::fake();
        Http::fake(['*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
        $this->seed(CreatorSmpPlayerSeeder::class);
        Player::where('name', 'Acid')->update(['team' => 'Zuid']);

        foreach ([1, 2] as $run) {
            $this->seed([CreatorSmpTeamSeeder::class, CreatorSmpDeathSeeder::class]);
        }

        // Every player is in a team, 45 each, and a team changed in the app is kept.
        $this->assertSame(0, Player::whereNull('team')->count());
        $this->assertSame(['Noord' => 44, 'Zuid' => 46], Player::query()->groupBy('team')->orderBy('team')->selectRaw('team, count(*) as players')->pluck('players', 'team')->all());
        $this->assertSame('Zuid', Player::where('name', 'Acid')->value('team'));
        $this->assertSame('Noord', Player::where('name', 'Jeremy Frieser')->value('team'));

        $this->assertSame(10, PlayerDeath::count());
        $death = Player::where('name', 'ikbenwela')->firstOrFail()->deaths()->sole();
        $this->assertSame('ikbenwela was doomed to fall by Pangi', $death->message);
        $this->assertSame('2026-10-05 19:51:38', $death->died_at->utc()->format('Y-m-d H:i:s'));
    }
}
