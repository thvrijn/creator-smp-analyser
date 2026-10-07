<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Stream;
use App\Services\StreamSpeakers;
use App\Services\VoiceProfiles;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Player $duncan;

    private Player $morrog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['services.voices.match_threshold' => 0.6, 'services.voices.match_margin' => 0.05, 'services.voices.min_seconds' => 20]);
        $this->duncan = Player::create(['name' => 'Duncan']);
        $this->morrog = Player::create(['name' => 'Morrog']);
        // Their own streams: speaker 0 is the streamer.
        $this->stream($this->duncan, [[0, 600, [1, 0, 0]]]);
        $this->stream($this->morrog, [[0, 600, [0, 1, 0]]]);
    }

    /** @param list<array{0: int, 1: float, 2: list<float>}> $voices speaker, seconds, embedding */
    private function stream(Player $player, array $voices): Stream
    {
        return $player->streams()->create([
            'title' => 'Stream', 'source' => 'test', 'started_at' => now(), 'transcription_status' => 'completed',
            'transcription_speakers' => array_map(fn (array $voice) => ['speaker' => $voice[0], 'seconds' => $voice[1], 'embedding' => $voice[2]], $voices),
        ]);
    }

    /** @return array<int, array{name: string, source: string}> */
    private function speakers(Stream $stream): array
    {
        return collect(app(StreamSpeakers::class)->list($stream->fresh()))->keyBy('speaker')->map(fn (array $speaker) => ['name' => $speaker['name'], 'source' => $speaker['source']])->all();
    }

    public function test_a_voice_in_another_stream_is_recognised_as_its_player(): void
    {
        $stream = $this->stream($this->duncan, [
            [0, 900, [0.9, 0.1, 0]],
            [1, 120, [0.1, 0.95, 0.05]],   // sounds like Morrog
            [2, 120, [0.6, 0.6, 0.5]],     // close to nobody in particular
            [3, 5, [0, 1, 0]],             // too short to trust
        ]);

        $speakers = $this->speakers($stream);
        // Speakers without segments do not show up in the list; check the matches directly.
        $matches = app(VoiceProfiles::class)->matches($stream);
        $this->assertSame([1], array_keys($matches));
        $this->assertSame($this->morrog->id, $matches[1]['player_id']);
        $this->assertGreaterThan(0.9, $matches[1]['similarity']);
        $this->assertSame([], $speakers); // no segments in this test stream
    }

    public function test_the_list_shows_the_match_until_named_or_set_to_unknown(): void
    {
        $stream = $this->stream($this->duncan, [[0, 900, [1, 0, 0]], [1, 120, [0, 1, 0]]]);
        foreach ([0, 1] as $speaker) {
            $stream->transcriptSegments()->create(['start_time' => $speaker, 'end_time' => $speaker + 1, 'text' => 'Hoi', 'speaker' => $speaker]);
        }

        $this->assertSame(['name' => 'Morrog', 'source' => 'matched'], $this->speakers($stream)[1]);

        $this->put("/streams/{$stream->id}/speakers/1", ['unknown' => true]);
        $this->assertSame(['name' => 'Spreker 1', 'source' => 'unknown'], $this->speakers($stream)[1]);

        $this->put("/streams/{$stream->id}/speakers/1", ['player_id' => null]);
        $this->assertSame('matched', $this->speakers($stream)[1]['source']);
    }

    public function test_a_player_is_matched_once_per_stream_and_never_the_streamer_again(): void
    {
        $stream = $this->stream($this->duncan, [
            [0, 900, [1, 0, 0]],
            [1, 120, [0.05, 1, 0]],
            [2, 300, [0, 1, 0.02]],   // the closest to Morrog
            [3, 120, [1, 0.02, 0]],   // sounds like Duncan, but Duncan is speaker 0 here
        ]);

        $matches = app(VoiceProfiles::class)->matches($stream);
        $this->assertSame([2], array_keys($matches));
    }

    public function test_a_name_given_by_hand_teaches_the_profile(): void
    {
        $lars = Player::create(['name' => 'Lars']);
        $first = $this->stream($this->duncan, [[0, 900, [1, 0, 0]], [1, 120, [0, 0, 1]]]);
        $first->transcriptSegments()->create(['start_time' => 0, 'end_time' => 1, 'text' => 'Hoi', 'speaker' => 1]);
        $later = $this->stream($this->morrog, [[0, 900, [0, 1, 0]], [1, 120, [0.05, 0, 1]]]);
        $this->assertSame([], app(VoiceProfiles::class)->matches($later));

        $this->put("/streams/{$first->id}/speakers/1", ['player_id' => $lars->id]);

        $this->assertSame($lars->id, app(VoiceProfiles::class)->matches($later)[1]['player_id']);
    }

    public function test_the_evaluation_command_compares_known_voices(): void
    {
        $this->stream($this->duncan, [[0, 600, [0.95, 0.05, 0]]]);

        $this->artisan('voices:evaluate')
            ->expectsOutputToContain('3 known voices from 2 players.')
            ->assertSuccessful();
    }
}
