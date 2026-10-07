<?php

namespace Tests\Feature;

use App\Jobs\DownloadVodJob;
use App\Jobs\ExtractStreamEventsJob;
use App\Jobs\TranscribeStreamJob;
use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use App\Services\EventExtractionWorker;
use App\Services\StreamJobCanceller;
use App\Services\TranscriptionWorker;
use App\Services\WorkerPool;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class CancelJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('local');
        $this->onlineWorker();
    }

    private function stream(array $attributes = []): Stream
    {
        $stream = Player::create(['name' => 'Sophie'])->streams()->create([
            'title' => 'Dag 1', 'source' => 'twitch', 'twitch_video_id' => '3001',
            'started_at' => '2026-10-04 16:00:00', 'ended_at' => '2026-10-04 20:00:00',
            'video_path' => 'streams/1/video/stream.m4a',
            ...$attributes,
        ]);
        Storage::disk('local')->put('streams/1/video/stream.m4a', 'audio');

        return $stream;
    }

    private function transcribe(Stream $stream): void
    {
        (new TranscribeStreamJob($stream->id))->handle(app(TranscriptionWorker::class), app(WorkerPool::class), app(StreamJobCanceller::class));
    }

    public function test_a_queued_transcription_is_cancelled_and_its_job_skips_itself(): void
    {
        Http::fake();
        $stream = $this->stream(['transcription_status' => 'queued', 'transcription_stage' => 'queued']);

        $this->post("/streams/{$stream->id}/cancel/transcription")->assertSessionHas('success', 'Geannuleerd.');

        $stream->refresh();
        $this->assertSame('pending', $stream->transcription_status);
        $this->assertSame('not_started', $stream->transcription_stage);

        $this->transcribe($stream);
        Http::assertNothingSent();
        $this->assertSame('pending', $stream->refresh()->transcription_status);
    }

    public function test_cancelling_a_re_transcription_keeps_the_old_transcript(): void
    {
        $stream = $this->stream(['transcription_status' => 'waiting', 'transcription_stage' => 'waiting_for_worker']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '2.000', 'text' => 'Oud']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '5.000', 'end_time' => '60.500', 'text' => 'Ook oud']);

        $this->post("/streams/{$stream->id}/cancel/transcription")->assertSessionHas('success');

        $stream->refresh();
        $this->assertSame('completed', $stream->transcription_status);
        $this->assertSame(100, $stream->transcription_progress);
        $this->assertSame(2, $stream->transcription_segment_count);
        $this->assertEquals(60.5, $stream->transcription_duration_seconds);
        $this->assertNotNull($stream->transcribed_at);
    }

    public function test_a_running_transcription_stops_and_keeps_the_old_transcript(): void
    {
        $stream = $this->stream(['transcription_status' => 'queued']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '2.000', 'text' => 'Oud']);
        // The cancel button is pressed while the worker is transcribing.
        Http::fake(['*/transcribe' => function () use ($stream) {
            $this->assertSame('processing', $stream->refresh()->transcription_status);
            $this->post("/streams/{$stream->id}/cancel/transcription")
                ->assertSessionHas('success', 'Wordt geannuleerd. De job stopt binnen enkele seconden.');
            $this->assertNotNull($stream->refresh()->transcription_cancel_requested_at);

            return Http::response(json_encode(['type' => 'segment', 'start' => 1, 'end' => 2, 'text' => 'Nieuw'])."\n");
        }]);

        $this->transcribe($stream);

        $stream->refresh();
        $this->assertSame('completed', $stream->transcription_status);
        $this->assertNull($stream->transcription_cancel_requested_at);
        $this->assertSame(['Oud'], TranscriptSegment::pluck('text')->all());
    }

    public function test_a_stalled_job_is_cancelled_at_once(): void
    {
        $stream = $this->stream(['transcription_status' => 'processing']);
        Stream::query()->whereKey($stream->id)->update(['updated_at' => now()->subMinutes(Stream::STALLED_AFTER_MINUTES + 1)]);

        $this->post("/streams/{$stream->id}/cancel/transcription")->assertSessionHas('success', 'Geannuleerd.');
        $this->assertSame('pending', $stream->refresh()->transcription_status);
    }

    public function test_a_running_analysis_stops_between_chunks_and_keeps_the_old_events(): void
    {
        config(['services.event_worker.chunk_seconds' => 10, 'services.event_worker.overlap_seconds' => 0]);
        $stream = $this->stream(['transcription_status' => 'completed', 'event_extraction_status' => 'queued']);
        foreach ([0, 20, 40] as $start) {
            TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => $start, 'end_time' => $start + 5, 'text' => 'Tekst']);
        }
        Event::create(['stream_id' => $stream->id, 'type' => 'other', 'title' => 'Oud event', 'description' => 'Oud', 'start_time' => 0, 'end_time' => 5, 'confidence' => 0.8]);
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->once()->andReturnUsing(function () use ($stream): array {
            $this->post("/streams/{$stream->id}/cancel/event_extraction")->assertSessionHas('success');

            return [];
        });

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(StreamJobCanceller::class));

        $stream->refresh();
        $this->assertSame('completed', $stream->event_extraction_status);
        $this->assertNull($stream->event_extraction_cancel_requested_at);
        $this->assertSame(['Oud event'], Event::pluck('title')->all());
    }

    public function test_a_queued_download_is_cancelled_and_never_starts(): void
    {
        Process::fake();
        $stream = $this->stream(['video_path' => null, 'video_download_status' => 'queued']);

        $this->post("/streams/{$stream->id}/cancel/video_download")->assertSessionHas('success', 'Geannuleerd.');
        $this->assertSame('pending', $stream->refresh()->video_download_status);

        DownloadVodJob::dispatchSync($stream->id);
        Process::assertNothingRan();
    }

    public function test_a_running_download_stops_yt_dlp_and_removes_the_partial_file(): void
    {
        $stream = $this->stream(['video_path' => null, 'video_download_status' => 'queued']);
        Process::fake(function (PendingProcess $process) use ($stream) {
            // yt-dlp has written part of the file when the cancel button is pressed.
            Storage::put("streams/{$stream->id}/video/twitch-3001.full.mp4", 'half');
            $this->post("/streams/{$stream->id}/cancel/video_download")->assertSessionHas('success');

            return Process::describe()->output('progress 1/100')->iterations(100);
        });

        DownloadVodJob::dispatchSync($stream->id);

        $stream->refresh();
        $this->assertSame('pending', $stream->video_download_status);
        $this->assertNull($stream->video_path);
        Storage::assertMissing("streams/{$stream->id}/video/twitch-3001.full.mp4");
    }

    public function test_nothing_to_cancel_and_unknown_tasks(): void
    {
        $stream = $this->stream(['transcription_status' => 'completed']);

        $this->post("/streams/{$stream->id}/cancel/transcription")->assertSessionHas('error', 'Er loopt hier niets om te annuleren.');
        $this->post("/streams/{$stream->id}/cancel/everything")->assertNotFound();
    }
}
