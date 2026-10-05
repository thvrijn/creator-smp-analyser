<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Stream;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_players_are_sorted_by_name_ignoring_case(): void
    {
        foreach (['APPELKAAS', 'alissarella', 'Acid'] as $name) {
            Player::create(['name' => $name]);
        }

        $this->get('/players')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('players.0.name', 'Acid')
            ->where('players.1.name', 'alissarella')
            ->where('players.2.name', 'APPELKAAS'));
    }

    public function test_a_player_can_be_updated(): void
    {
        $player = Player::create(['name' => 'Sophie']);

        $this->put('/players/'.$player->id, ['name' => 'Sophie van SMP'])->assertRedirect('/players');

        $this->assertDatabaseHas('players', ['id' => $player->id, 'name' => 'Sophie van SMP']);
    }

    public function test_a_player_twitch_channel_is_saved_cleared_and_validated(): void
    {
        $player = Player::create(['name' => 'Acid']);

        $this->put('/players/'.$player->id, ['name' => 'Acid', 'twitch_login' => 'acidtwee'])->assertSessionDoesntHaveErrors();
        $this->assertSame('acidtwee', $player->fresh()->twitch_login);
        $this->get('/players/'.$player->id)->assertInertia(fn (Assert $page) => $page->where('player.twitch_login', 'acidtwee'));

        $this->put('/players/'.$player->id, ['name' => 'Acid', 'twitch_login' => 'https://www.twitch.tv/acidtwee'])->assertSessionHasErrors('twitch_login');
        $this->assertSame('acidtwee', $player->fresh()->twitch_login);

        $this->put('/players/'.$player->id, ['name' => 'Acid', 'twitch_login' => ''])->assertSessionDoesntHaveErrors();
        $this->assertNull($player->fresh()->twitch_login);
    }

    public function test_editing_a_player_from_its_page_returns_to_that_page(): void
    {
        $player = Player::create(['name' => 'Sophie']);

        $this->from('/players/'.$player->id)->put('/players/'.$player->id, ['name' => 'Sophie van SMP'])->assertRedirect('/players/'.$player->id);
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

    public function test_a_player_photo_is_stored_served_replaced_and_removed(): void
    {
        Storage::fake();

        $this->post('/players', ['name' => 'Sophie', 'photo' => $this->photo()])->assertRedirect('/players');
        $player = Player::firstOrFail();
        Storage::assertExists($player->photo_path);
        $this->get($player->photoUrl())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/players')->assertInertia(fn (Assert $page) => $page->where('players.0.photo_url', $player->photoUrl()));

        // Edits are a POST with _method=put, because PHP does not parse multipart PUT bodies.
        $oldPhoto = $player->photo_path;
        $this->post('/players/'.$player->id, ['_method' => 'put', 'name' => 'Sophie', 'photo' => $this->photo('new.png')])->assertRedirect('/players');
        $player->refresh();
        Storage::assertMissing($oldPhoto);
        Storage::assertExists($player->photo_path);

        $this->post('/players/'.$player->id, ['_method' => 'put', 'name' => 'Sophie', 'remove_photo' => '1'])->assertRedirect('/players');
        Storage::assertMissing($player->photo_path);
        $this->assertNull($player->refresh()->photo_path);
        $this->get('/players/'.$player->id.'/photo')->assertNotFound();
    }

    public function test_a_photo_that_is_not_a_bitmap_image_is_rejected(): void
    {
        Storage::fake();
        $svg = UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->from('/players')->post('/players', ['name' => 'Sophie', 'photo' => $svg])->assertRedirect('/players')->assertSessionHasErrors('photo');
        $this->assertDatabaseCount('players', 0);
    }

    public function test_deleting_a_player_deletes_their_photo(): void
    {
        Storage::fake();
        $this->post('/players', ['name' => 'Sophie', 'photo' => $this->photo()]);
        $player = Player::firstOrFail();

        $this->delete('/players/'.$player->id)->assertRedirect('/players');
        Storage::assertMissing($player->photo_path);
    }

    private function photo(string $name = 'sophie.png'): UploadedFile
    {
        // A real 1x1 PNG: UploadedFile::fake()->image() needs GD, which the PHP image does not have.
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }
}
