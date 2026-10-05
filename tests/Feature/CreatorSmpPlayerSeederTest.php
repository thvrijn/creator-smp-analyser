<?php

namespace Tests\Feature;

use App\Models\Player;
use Database\Seeders\CreatorSmpPlayerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreatorSmpPlayerSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_every_player_with_a_photo_and_keeps_existing_ones(): void
    {
        Storage::fake();
        Http::fake(['storage.creatorsmp.nl/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
        $existing = Player::create(['name' => 'Royalistiq', 'photo_path' => 'players/own.jpg', 'twitch_login' => 'own_channel']);

        $this->seed(CreatorSmpPlayerSeeder::class);
        $this->seed(CreatorSmpPlayerSeeder::class);

        $this->assertSame(90, Player::count());
        $this->assertSame('players/own.jpg', $existing->fresh()->photo_path);
        $this->assertSame('own_channel', $existing->fresh()->twitch_login);
        $this->assertSame('acidtwee', Player::where('name', 'Acid')->value('twitch_login'));
        $this->assertNull(Player::where('name', 'Mick')->value('twitch_login'));
        $photo = Player::where('name', 'Acid')->value('photo_path');
        $this->assertStringEndsWith('.jpg', $photo);
        Storage::assertExists($photo);
        Http::assertSentCount(89); // the second run downloads nothing
    }

    public function test_a_player_whose_photo_fails_to_download_is_still_added(): void
    {
        Storage::fake();
        Http::fake(['*' => Http::response('Not found', 404, ['Content-Type' => 'text/html'])]);

        $this->seed(CreatorSmpPlayerSeeder::class);

        $this->assertSame(90, Player::count());
        $this->assertSame(0, Player::whereNotNull('photo_path')->count());
    }
}
