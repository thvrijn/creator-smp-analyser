<?php

namespace Tests\Feature;

use App\Jobs\TranscribeStreamJob;
use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscriptionJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->onlineWorker();
    }

    public function test_new_streams_are_not_queued_until_transcribe_is_clicked(): void
    {
        Queue::fake();
        $stream = $this->createStream();
        $this->assertSame('pending', $stream->transcription_status);
        $this->assertSame('not_started', $stream->transcription_stage);
        Queue::assertNothingPushed();
    }

    public function test_transcribe_endpoint_dispatches_a_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'video');

        $this->post('/streams/'.$stream->id.'/transcribe')->assertRedirect('/streams')->assertSessionHas('success');

        Queue::assertPushed(TranscribeStreamJob::class, fn (TranscribeStreamJob $job) => $job->streamId === $stream->id);
        $this->assertDatabaseHas('streams', ['id' => $stream->id, 'transcription_status' => 'queued', 'transcription_stage' => 'queued']);
    }

    public function test_queued_or_processing_stream_is_not_dispatched_again(): void
    {
        Queue::fake();
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'video');

        foreach (['queued', 'processing'] as $status) {
            $stream->update(['transcription_status' => $status]);
            $this->post('/streams/'.$stream->id.'/transcribe')->assertRedirect('/streams')->assertSessionHas('error');
            $this->assertSame($status, $stream->refresh()->transcription_status);
        }

        Queue::assertNothingPushed();
    }

    public function test_retry_after_a_failed_attempt_ends_as_failed_not_stuck_processing(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'video');
        $stream->update(['transcription_status' => 'failed']);
        Http::fake(['*/transcribe' => Http::response(json_encode(['type' => 'error', 'error' => 'Whisper failed'])."\n")]);

        try {
            (new TranscribeStreamJob($stream->id))->handle(app(\App\Services\TranscriptionWorker::class), app(\App\Services\WorkerPool::class));
        } catch (\RuntimeException) {
        }

        $this->assertSame('failed', $stream->refresh()->transcription_status);
        $this->assertSame('Whisper failed', $stream->transcription_error);
    }

    public function test_queued_job_is_picked_up_by_the_worker(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'video');
        $stream->update(['transcription_status' => 'queued', 'transcription_stage' => 'queued']);
        Http::fake(['*/transcribe' => Http::response(json_encode(['type' => 'segment', 'start' => 1, 'end' => 2, 'text' => 'Hallo'])."\n")]);

        (new TranscribeStreamJob($stream->id))->handle(app(\App\Services\TranscriptionWorker::class), app(\App\Services\WorkerPool::class));

        $this->assertSame('completed', $stream->refresh()->transcription_status);
        $this->assertSame(1, $stream->transcriptSegments()->count());
    }

    public function test_only_the_transcription_ranges_are_sent_to_the_worker(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'video');
        $stream->update(['transcription_status' => 'queued', 'transcription_stage' => 'queued', 'transcription_ranges' => [[0, 793], [10000, 18801]]]);
        Http::fake(['*/transcribe' => Http::response(json_encode(['type' => 'segment', 'start' => 10001, 'end' => 10003, 'text' => 'Hallo'])."\n")]);

        (new TranscribeStreamJob($stream->id))->handle(app(\App\Services\TranscriptionWorker::class), app(\App\Services\WorkerPool::class));

        Http::assertSent(fn ($request) => $request['ranges'] === [[0, 793], [10000, 18801]]);
        $this->assertSame('completed', $stream->refresh()->transcription_status);
    }

    public function test_transcription_status_endpoint_returns_telemetry(): void
    {
        $stream = $this->createStream();
        $stream->update([
            'transcription_status' => 'processing',
            'transcription_stage' => 'transcribing',
            'transcription_progress' => 62,
            'transcription_processed_seconds' => 6140,
            'transcription_duration_seconds' => 9945,
            'transcription_segment_count' => 394,
            'transcription_started_at' => now(),
            'transcription_eta_seconds' => 123.5,
        ]);

        $this->getJson('/streams/'.$stream->id.'/transcription-status')
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('stage', 'transcribing')
            ->assertJsonPath('progress', 62)
            ->assertJsonPath('segment_count', 394)
            ->assertJsonPath('eta_seconds', 123.5);
    }

    public function test_stream_without_video_cannot_be_dispatched(): void
    {
        Queue::fake();
        $stream = $this->createStream();

        $this->post('/streams/'.$stream->id.'/transcribe')->assertRedirect('/streams')->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    public function test_successful_job_saves_segments_and_completes_stream(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'video');
        Http::fake(['*' => Http::response(['segments' => [
            ['start' => 1.25, 'end' => 2.5, 'text' => ' Hello '],
        ]])]);

        (new TranscribeStreamJob($stream->id))->handle(app(\App\Services\TranscriptionWorker::class), app(\App\Services\WorkerPool::class));

        $stream->refresh();
        $this->assertSame('completed', $stream->transcription_status);
        $this->assertSame('completed', $stream->transcription_stage);
        $this->assertSame(100, $stream->transcription_progress);
        $this->assertNotNull($stream->transcribed_at);
        $this->assertNull($stream->transcription_error);
        $this->assertDatabaseHas('transcript_segments', ['stream_id' => $stream->id, 'start_time' => '1.250', 'end_time' => '2.500', 'text' => 'Hello']);
    }

    public function test_failed_job_preserves_existing_segments(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'video');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '2.000', 'text' => 'Existing']);
        Http::fake(['*' => Http::response(['error' => 'worker unavailable'], 503)]);

        $job = new TranscribeStreamJob($stream->id);
        try {
            $job->handle(app(\App\Services\TranscriptionWorker::class), app(\App\Services\WorkerPool::class));
        } catch (\Throwable $exception) {
            $job->failed($exception);
        }

        $stream->refresh();
        $this->assertSame('failed', $stream->transcription_status);
        $this->assertSame('failed', $stream->transcription_stage);
        $this->assertStringContainsString('worker unavailable', $stream->transcription_error);
        $this->assertDatabaseHas('transcript_segments', ['text' => 'Existing']);
    }

    public function test_processing_stream_is_not_processed_twice(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        $stream->update(['transcription_status' => 'processing']);
        Storage::disk('local')->put($stream->video_path, 'video');
        Http::fake();

        (new TranscribeStreamJob($stream->id))->handle(app(\App\Services\TranscriptionWorker::class), app(\App\Services\WorkerPool::class));

        Http::assertNothingSent();
        $this->assertDatabaseHas('streams', ['id' => $stream->id, 'transcription_status' => 'processing']);
    }

    public function test_successful_retry_replaces_previous_segments(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'video');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '2.000', 'text' => 'Old']);
        Http::fake(['*' => Http::response(['segments' => [['start' => 5, 'end' => 6, 'text' => 'New']]])]);

        (new TranscribeStreamJob($stream->id))->handle(app(\App\Services\TranscriptionWorker::class), app(\App\Services\WorkerPool::class));

        $this->assertDatabaseMissing('transcript_segments', ['text' => 'Old']);
        $this->assertDatabaseHas('transcript_segments', ['text' => 'New']);
    }

    public function test_streaming_worker_progress_is_saved_during_job(): void
    {
        Storage::fake('local');
        $stream = $this->createStream('streams/1/video/stream.mp4');
        Storage::disk('local')->put($stream->video_path, 'video');
        $body = implode("\n", [
            json_encode(['type' => 'duration', 'duration_seconds' => 1000]),
            json_encode(['type' => 'stage', 'stage' => 'extracting_audio']),
            json_encode(['type' => 'progress', 'stage' => 'extracting_audio', 'processed_seconds' => 500, 'progress' => 50]),
            json_encode(['type' => 'stage', 'stage' => 'transcribing']),
            json_encode(['type' => 'segment', 'start' => 250, 'end' => 300, 'text' => 'Hello']),
            json_encode(['type' => 'progress', 'stage' => 'transcribing', 'processed_seconds' => 300, 'progress' => 30, 'segment_count' => 1]),
            json_encode(['type' => 'completed', 'stage' => 'completed', 'processed_seconds' => 1000, 'progress' => 100, 'segment_count' => 1]),
            '',
        ]);
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'application/x-ndjson'])]);

        (new TranscribeStreamJob($stream->id))->handle(app(\App\Services\TranscriptionWorker::class), app(\App\Services\WorkerPool::class));

        $stream->refresh();
        $this->assertSame('completed', $stream->transcription_stage);
        $this->assertSame(100, $stream->transcription_progress);
        $this->assertSame(1000.0, $stream->transcription_duration_seconds);
        $this->assertSame(1000.0, $stream->transcription_processed_seconds);
        $this->assertSame(1, $stream->transcription_segment_count);
        $this->assertSame(0.0, $stream->transcription_eta_seconds);
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
