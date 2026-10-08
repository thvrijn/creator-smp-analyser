<?php

namespace Tests\Feature;

use App\Jobs\MatchSpeakerTextJob;
use App\Models\Player;
use App\Models\Stream;
use App\Services\SpeakerTextMatches;
use App\Services\StreamSpeakers;
use App\Services\VoiceProfiles;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SpeakerTextMatchTest extends TestCase
{
    use RefreshDatabase;

    private Player $morrog;

    private Player $jeremy;

    private Stream $morrogStream;

    private Stream $jeremyStream;

    /** What Jeremy says, at seconds after 15:00 server time. */
    private const SENTENCES = [
        [0, 'Yo Morrog, kom even naar mijn basis toe man.'],
        [20, 'Ik heb net drie diamanten gevonden in de grot.'],
        [45, 'Moet je kijken hoe groot deze ravijn eigenlijk is.'],
        [70, 'We moeten echt een nether portal gaan bouwen vanavond.'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['services.voices.match_threshold' => 0.6, 'services.voices.match_margin' => 0.05, 'services.voices.min_seconds' => 20]);
        $this->morrog = Player::create(['name' => 'Morrog']);
        $this->jeremy = Player::create(['name' => 'Jeremy']);
        // Morrog went live at 14:00, Jeremy at 14:10: 15:00 is 3600 s into Morrog's file and 3000 s into Jeremy's.
        $this->morrogStream = $this->stream($this->morrog, '2026-10-05 14:00:00+00');
        $this->jeremyStream = $this->stream($this->jeremy, '2026-10-05 14:10:00+00');
    }

    private function stream(Player $player, string $startedAt): Stream
    {
        return $player->streams()->create([
            'title' => $player->name.' dag 2', 'source' => 'twitch', 'started_at' => $startedAt, 'ended_at' => '2026-10-05 20:00:00+00',
            'transcription_status' => 'completed',
            'transcription_speakers' => [
                ['speaker' => 0, 'seconds' => 900, 'embedding' => [1.0, 0.0]],
                ['speaker' => 3, 'seconds' => 120, 'embedding' => [0.0, 1.0]],
            ],
        ]);
    }

    private function say(Stream $stream, float $time, string $text, int $speaker): void
    {
        $stream->transcriptSegments()->create(['start_time' => $time, 'end_time' => $time + 4, 'text' => $text, 'speaker' => $speaker]);
    }

    /** Jeremy talks in his own stream (speaker 0) and is heard in Morrog's as speaker 3, a few seconds later. */
    private function talkTogether(int $sentences = 4): void
    {
        foreach (array_slice(self::SENTENCES, 0, $sentences) as [$at, $text]) {
            $this->say($this->jeremyStream, 3000 + $at, $text, 0);
            // Transcribed a bit differently from Morrog's side, and 3 s later.
            $this->say($this->morrogStream, 3600 + $at + 3, mb_strtolower(rtrim($text, '.')).' hè', 3);
        }
        $this->say($this->morrogStream, 3610, 'Ja ik kom eraan, wacht even op mij hoor.', 0);
    }

    /** @return array<int, array<string, mixed>> */
    private function speakers(Stream $stream): array
    {
        return collect(app(StreamSpeakers::class)->list($stream->fresh()))->keyBy('speaker')->all();
    }

    public function test_a_speaker_saying_what_another_streamer_says_at_that_moment_is_that_player(): void
    {
        $this->talkTogether();

        app(SpeakerTextMatches::class)->refresh($this->morrogStream);

        $speaker = $this->speakers($this->morrogStream)[3];
        $this->assertSame('Jeremy', $speaker['name']);
        $this->assertSame('matched', $speaker['source']);
        $this->assertSame('text', $speaker['matched_by']);
        $this->assertSame(4, $speaker['text_hits']);
        $this->assertSame($this->jeremyStream->id, $speaker['text_stream_id']);
        $this->assertSame('Morrog', $this->speakers($this->morrogStream)[0]['name']);
    }

    public function test_the_other_direction_is_matched_in_the_same_run(): void
    {
        $this->talkTogether();
        // Morrog is heard in Jeremy's stream as speaker 2.
        foreach (['Ik heb een hele stack ijzer bij me voor jou.', 'Zullen we samen naar het dorp lopen straks?', 'Pas op daar staat een creeper achter je.'] as $i => $text) {
            $this->say($this->morrogStream, 3700 + $i * 15, $text, 0);
            $this->say($this->jeremyStream, 3100 + $i * 15 + 2, $text, 2);
        }

        app(SpeakerTextMatches::class)->refresh($this->morrogStream);

        $this->assertSame('Morrog', $this->speakers($this->jeremyStream)[2]['name']);
        $this->assertSame('text', $this->speakers($this->jeremyStream)[2]['matched_by']);
    }

    public function test_the_same_words_at_another_time_or_too_few_sentences_do_not_count(): void
    {
        foreach (self::SENTENCES as [$at, $text]) {
            $this->say($this->jeremyStream, 3000 + $at, $text, 0);
            // Ten minutes later in server time: not the same moment.
            $this->say($this->morrogStream, 4200 + $at, $text, 3);
        }
        // Only two sentences at the right time: not enough.
        $this->say($this->morrogStream, 3600, self::SENTENCES[0][1], 4);
        $this->say($this->morrogStream, 3620, self::SENTENCES[1][1], 4);
        // Short sentences are said by everyone.
        foreach ([0, 20, 45, 70] as $at) {
            $this->say($this->jeremyStream, 3000 + $at + 1, 'Ja dat klopt', 0);
            $this->say($this->morrogStream, 3600 + $at + 1, 'Ja dat klopt', 5);
        }

        app(SpeakerTextMatches::class)->refresh($this->morrogStream);

        $this->assertSame([], app(SpeakerTextMatches::class)->matches($this->morrogStream->fresh()));
    }

    public function test_a_name_by_hand_wins_and_a_text_match_becomes_a_known_voice(): void
    {
        $this->talkTogether();
        app(SpeakerTextMatches::class)->refresh($this->morrogStream);

        // Speaker 3's voice ([0, 1]) now counts as Jeremy's, next to Jeremy's own speaker 0 ([1, 0]).
        $voices = collect(iterator_to_array(app(VoiceProfiles::class)->knownVoices(), false));
        $this->assertTrue($voices->contains(fn (array $voice) => $voice[0] === $this->jeremy->id && $voice[2] === $this->morrogStream->id && $voice[1] === [0.0, 1.0]));

        $this->put("/streams/{$this->morrogStream->id}/speakers/3", ['unknown' => true]);
        $this->assertSame('unknown', $this->speakers($this->morrogStream)[3]['source']);
    }

    public function test_a_finished_transcription_and_renaming_the_streamer_redo_the_matches(): void
    {
        Queue::fake();
        $this->say($this->morrogStream, 10, 'Hallo chat', 0);
        $this->say($this->morrogStream, 20, 'Hallo', 1);

        $this->put("/streams/{$this->morrogStream->id}/speakers/1", ['label' => 'Gast']);
        Queue::assertNotPushed(MatchSpeakerTextJob::class);

        $this->put("/streams/{$this->morrogStream->id}/speakers/0", ['label' => 'TTS']);
        Queue::assertPushed(MatchSpeakerTextJob::class, fn (MatchSpeakerTextJob $job) => $job->streamId === $this->morrogStream->id);
    }

    public function test_the_command_matches_every_transcribed_stream(): void
    {
        $this->talkTogether();

        $this->artisan('speakers:match-text')->assertSuccessful();

        $this->assertSame('Jeremy', $this->speakers($this->morrogStream)[3]['name']);
    }
}
