<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscribeStreamCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onlineWorker();
    }

    public function test_a_stream_without_video_cannot_be_transcribed(): void
    {
        $stream = $this->createStream();

        $this->artisan('stream:transcribe', ['stream' => $stream->id, '--sync' => true])
            ->expectsOutput("Stream #{$stream->id} has no video file.")
            ->assertExitCode(1);
    }

    public function test_a_missing_stream_file_is_reported(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/missing.mp4');

        $this->artisan('stream:transcribe', ['stream' => $stream->id, '--sync' => true])
            ->expectsOutput("The stream video does not exist: {$stream->video_path}")
            ->assertExitCode(1);
    }

    public function test_transcription_segments_are_saved_with_precise_timestamps(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'fake video');
        Http::fake([
            '*' => Http::response([
                'stream_id' => $stream->id,
                'segments' => [
                    ['start' => 12.34, 'end' => 16.87, 'text' => '  We moeten naar het dorp.  '],
                    ['start' => 20.0, 'end' => 21.5, 'text' => '   '],
                ],
            ]),
        ]);

        $this->artisan('stream:transcribe', ['stream' => $stream->id, '--sync' => true])
            ->expectsOutput('Done: 1 segments saved.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('streams', ['id' => $stream->id, 'transcription_status' => 'completed']);

        $this->assertDatabaseHas('transcript_segments', [
            'stream_id' => $stream->id,
            'start_time' => '12.340',
            'end_time' => '16.870',
            'text' => 'We moeten naar het dorp.',
        ]);
        $this->assertDatabaseCount('transcript_segments', 1);
    }

    public function test_reprocessing_replaces_existing_segments_without_duplicates(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'fake video');
        TranscriptSegment::create([
            'stream_id' => $stream->id,
            'start_time' => '1.000',
            'end_time' => '2.000',
            'text' => 'Old transcript',
        ]);
        Http::fake(['*' => Http::response(['segments' => [
            ['start' => 4.2, 'end' => 5.4, 'text' => 'New transcript'],
        ]])]);

        $this->artisan('stream:transcribe', ['stream' => $stream->id, '--sync' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('transcript_segments', ['text' => 'Old transcript']);
        $this->assertDatabaseCount('transcript_segments', 1);
        $this->assertDatabaseHas('transcript_segments', ['text' => 'New transcript']);
    }

    public function test_empty_worker_result_is_rejected_without_deleting_existing_segments(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'fake video');
        TranscriptSegment::create([
            'stream_id' => $stream->id,
            'start_time' => '1.000',
            'end_time' => '2.000',
            'text' => 'Existing transcript',
        ]);
        Http::fake(['*' => Http::response(['segments' => []])]);

        $this->artisan('stream:transcribe', ['stream' => $stream->id, '--sync' => true])
            ->expectsOutput('De transcriptie leverde geen tekst op.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('transcript_segments', ['text' => 'Existing transcript']);
    }

    private function createStream(?string $videoPath = null): Stream
    {
        $player = Player::create(['name' => 'Sophie']);

        return Stream::create([
            'player_id' => $player->id,
            'title' => 'Stream #1',
            'started_at' => '2026-10-03 10:00:00+00',
            'source' => 'development',
            'video_path' => $videoPath,
        ]);
    }
}
