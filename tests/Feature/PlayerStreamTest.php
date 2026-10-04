<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Stream;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_can_be_created(): void
    {
        $player = Player::create(['name' => 'Sophie']);

        $this->assertDatabaseHas('players', [
            'id' => $player->id,
            'name' => 'Sophie',
        ]);
    }

    public function test_player_names_must_be_unique(): void
    {
        Player::create(['name' => 'Sophie']);

        $this->expectException(QueryException::class);
        Player::create(['name' => 'Sophie']);
    }

    public function test_a_stream_can_be_linked_to_a_player_and_relationships_work(): void
    {
        $player = Player::create(['name' => 'Lars']);
        $stream = Stream::create([
            'player_id' => $player->id,
            'title' => 'Bouwstream',
            'started_at' => '2026-10-03 10:00:00+00',
            'source' => 'development',
        ]);

        $this->assertTrue($player->streams->contains($stream));
        $this->assertTrue($stream->player->is($player));
        $this->assertNull($stream->ended_at);
    }

    public function test_a_stream_requires_a_valid_player(): void
    {
        $this->expectException(QueryException::class);

        Stream::create([
            'player_id' => 999999,
            'title' => 'Ongeldige stream',
            'started_at' => '2026-10-03 10:00:00+00',
            'source' => 'development',
        ]);
    }
}
