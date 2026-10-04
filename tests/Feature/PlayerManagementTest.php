<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Stream;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlayerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_the_players_page_loads_existing_players_and_stream_counts(): void
    {
        $player = Player::create(['name' => 'Sophie']);
        Stream::create(['player_id' => $player->id, 'title' => 'Evening SMP', 'started_at' => '2026-10-03 19:00:00+00', 'source' => 'youtube']);
        Stream::create(['player_id' => $player->id, 'title' => 'Late night SMP', 'started_at' => '2026-10-03 21:00:00+00', 'source' => 'twitch']);

        $this->get('/players')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Players/Index')
            ->has('players', 1)
            ->where('players.0.name', 'Sophie')
            ->where('players.0.streams_count', 2));
    }

    public function test_a_player_can_be_created(): void
    {
        $this->post('/players', ['name' => 'Lars'])->assertRedirect('/players');

        $this->assertDatabaseHas('players', ['name' => 'Lars']);
    }

    public function test_a_player_without_a_name_is_rejected(): void
    {
        $this->from('/players')->post('/players', ['name' => ''])->assertRedirect('/players')->assertSessionHasErrors('name');
        $this->assertDatabaseCount('players', 0);
    }

    public function test_duplicate_player_names_are_rejected(): void
    {
        Player::create(['name' => 'Sophie']);

        $this->from('/players')->post('/players', ['name' => 'Sophie'])->assertRedirect('/players')->assertSessionHasErrors('name');
        $this->assertDatabaseCount('players', 1);
    }

    public function test_a_player_can_be_updated(): void
    {
        $player = Player::create(['name' => 'Sophie']);

        $this->put('/players/'.$player->id, ['name' => 'Sophie van SMP'])->assertRedirect('/players');

        $this->assertDatabaseHas('players', ['id' => $player->id, 'name' => 'Sophie van SMP']);
    }

    public function test_a_player_can_keep_its_current_name(): void
    {
        $player = Player::create(['name' => 'Sophie']);

        $this->put('/players/'.$player->id, ['name' => 'Sophie'])->assertRedirect('/players')->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('players', ['id' => $player->id, 'name' => 'Sophie']);
    }

    public function test_a_player_without_streams_can_be_deleted(): void
    {
        $player = Player::create(['name' => 'Mark']);

        $this->delete('/players/'.$player->id)->assertRedirect('/players');

        $this->assertDatabaseMissing('players', ['id' => $player->id]);
    }

    public function test_a_player_with_streams_cannot_be_deleted(): void
    {
        $player = Player::create(['name' => 'Lars']);
        Stream::create(['player_id' => $player->id, 'title' => 'Protected stream', 'started_at' => '2026-10-03 21:00:00+00', 'source' => 'local']);

        $this->delete('/players/'.$player->id)->assertRedirect('/players')->assertSessionHas('error');

        $this->assertDatabaseHas('players', ['id' => $player->id]);
    }
}
