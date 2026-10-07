<?php

namespace Tests\Feature;

use App\Jobs\TranscribeStreamJob;
use App\Models\ActivityLog;
use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use App\Services\StreamJobCanceller;
use App\Services\TranscriptionWorker;
use App\Services\WorkerPool;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SpeakerCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Stream $stream;

    private Player $morrog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->stream = Player::create(['name' => 'Duncan'])->streams()->create([
            'title' => 'Dag 1', 'source' => 'test', 'started_at' => now(), 'transcription_status' => 'completed',
            'transcription_speakers' => [
                ['speaker' => 0, 'seconds' => 30.0, 'embedding' => [1.0, 0.0]],
                ['speaker' => 1, 'seconds' => 10.0, 'embedding' => [0.0, 1.0]],
                ['speaker' => 2, 'seconds' => 30.0, 'embedding' => [0.0, 0.0]],
            ],
        ]);
        $this->morrog = Player::create(['name' => 'Morrog']);
        foreach ([[0, 0, 30], [1, 30, 40], [2, 40, 70], [null, 70, 75]] as [$speaker, $start, $end]) {
            TranscriptSegment::create(['stream_id' => $this->stream->id, 'start_time' => $start, 'end_time' => $end, 'text' => "Zin {$start}", 'speaker' => $speaker]);
        }
    }

    private function speakers(): array
    {
        $names = [];
        $this->get("/streams/{$this->stream->id}?tab=transcript")->assertInertia(function (Assert $page) use (&$names) {
            $names = collect($page->toArray()['props']['speakers'])->pluck('name', 'speaker')->all();
        });

        return $names;
    }

    public function test_the_stream_page_lists_the_speakers_with_default_names(): void
    {
        $this->get("/streams/{$this->stream->id}")->assertInertia(fn (Assert $page) => $page
            ->where('speakers.0', ['speaker' => 0, 'name' => 'Duncan', 'source' => 'default', 'named' => false, 'player_id' => null, 'label' => null, 'similarity' => null, 'seconds' => 30, 'segment_count' => 1])
            ->where('speakers.1.name', 'Spreker 1')
            ->has('players', 2));
    }

    public function test_a_speaker_is_named_after_a_player_or_a_free_name_and_can_be_reset(): void
    {
        $this->put("/streams/{$this->stream->id}/speakers/1", ['player_id' => $this->morrog->id])->assertSessionHas('success', 'Spreker 1 is nu Morrog.');
        $this->put("/streams/{$this->stream->id}/speakers/2", ['label' => 'Chat-TTS']);
        $this->assertSame([0 => 'Duncan', 1 => 'Morrog', 2 => 'Chat-TTS'], $this->speakers());

        $this->put("/streams/{$this->stream->id}/speakers/1", ['player_id' => null]);
        $this->assertSame('Spreker 1', $this->speakers()[1]);
        $this->assertSame(1, $this->stream->speakerNames()->count());

        $this->put("/streams/{$this->stream->id}/speakers/9", ['label' => 'X'])->assertNotFound();
    }

    public function test_merging_moves_the_segments_name_and_voice(): void
    {
        $this->put("/streams/{$this->stream->id}/speakers/2", ['player_id' => $this->morrog->id]);

        $this->post("/streams/{$this->stream->id}/speakers/2/merge", ['into' => 1])->assertSessionHas('success', 'Morrog is samengevoegd met Spreker 1.');

        $this->assertSame([0 => 'Duncan', 1 => 'Morrog'], $this->speakers());
        $this->assertSame(2, TranscriptSegment::where('speaker', 1)->count());
        $voices = collect($this->stream->fresh()->transcription_speakers)->keyBy('speaker');
        $this->assertSame([0, 1], $voices->keys()->all());
        $this->assertEquals(40.0, $voices[1]['seconds']);
        // Averaged by speaking time: 10 s of [0, 1] and 30 s of [0, 0].
        $this->assertEquals([0.0, 0.25], $voices[1]['embedding']);

        $this->post("/streams/{$this->stream->id}/speakers/1/merge", ['into' => 1])->assertSessionHasErrors('into');
    }

    public function test_one_segment_moves_to_another_new_or_no_speaker(): void
    {
        $segment = TranscriptSegment::where('speaker', 1)->sole();

        $this->put("/segments/{$segment->id}/speaker", ['speaker' => 0]);
        $this->assertSame(0, $segment->fresh()->speaker);
        $this->put("/segments/{$segment->id}/speaker", ['speaker' => 'new']);
        $this->assertSame(3, $segment->fresh()->speaker);
        $this->put("/segments/{$segment->id}/speaker", ['speaker' => null]);
        $this->assertNull($segment->fresh()->speaker);
        $this->put("/segments/{$segment->id}/speaker", ['speaker' => 7])->assertSessionHasErrors('speaker');

        $this->assertSame('Spreker van een zin aangepast', ActivityLog::latest('id')->first()->description);
    }

    public function test_a_new_transcript_forgets_the_names(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('streams/1/a.m4a', 'audio');
        $this->stream->update(['video_path' => 'streams/1/a.m4a', 'transcription_status' => 'queued']);
        $this->put("/streams/{$this->stream->id}/speakers/1", ['player_id' => $this->morrog->id]);
        $this->onlineWorker();
        Http::fake(['*/transcribe' => Http::response(json_encode(['type' => 'segment', 'start' => 1, 'end' => 2, 'text' => 'Nieuw'])."\n")]);

        (new TranscribeStreamJob($this->stream->id))->handle(app(TranscriptionWorker::class), app(WorkerPool::class), app(StreamJobCanceller::class));

        $this->assertSame(0, $this->stream->speakerNames()->count());
    }
}
