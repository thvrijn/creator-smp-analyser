<?php

namespace Tests\Feature;

use App\Jobs\DownloadVodJob;
use App\Jobs\ExtractStreamEventsJob;
use App\Jobs\TranscribeStreamJob;
use App\Models\Player;
use App\Models\Stream;
use App\Services\TranscriptionWorker;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StalledJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->onlineWorker();
        Queue::fake();
        Storage::fake();
        $this->travelTo(now()->startOfMinute());
    }

    private function stream(array $attributes): Stream
    {
        Storage::put('streams/1/video/stream.mp4', 'audio');

        return Player::firstOrCreate(['name' => 'Paraduze'])->streams()->create([
            'title' => 'Dag 1', 'source' => 'twitch', 'twitch_video_id' => '5001', 'video_path' => 'streams/1/video/stream.mp4',
            'started_at' => now()->subHours(5), 'ended_at' => now()->subHour(), ...$attributes,
        ]);
    }

    public function test_a_transcription_without_progress_for_ten_minutes_can_be_started_again(): void
    {
        $stream = $this->stream(['transcription_status' => 'processing', 'transcription_stage' => 'transcribing']);

        $this->travel(9)->minutes();
        $this->getJson("/streams/{$stream->id}/transcription-status")->assertJsonPath('transcription_stalled', false);
        $this->post("/streams/{$stream->id}/transcribe")->assertSessionHas('error');
        Queue::assertNothingPushed();

        $this->travel(2)->minutes();
        $this->getJson("/streams/{$stream->id}/transcription-status")->assertJsonPath('transcription_stalled', true);
        $this->post("/streams/{$stream->id}/transcribe")->assertSessionHas('success');
        $this->assertSame('queued', $stream->fresh()->transcription_status);
        Queue::assertPushed(TranscribeStreamJob::class);
    }

    public function test_a_stalled_analysis_and_download_can_be_started_again(): void
    {
        $stream = $this->stream(['transcription_status' => 'completed', 'event_extraction_status' => 'processing']);
        $stream->transcriptSegments()->create(['start_time' => 1, 'end_time' => 2, 'text' => 'Hallo']);
        $vod = $this->stream(['twitch_video_id' => '5002', 'video_path' => null, 'video_download_status' => 'processing']);

        $this->travel(11)->minutes();

        $this->post("/streams/{$stream->id}/extract-events")->assertSessionHas('success');
        $this->post("/streams/{$vod->id}/download-audio")->assertSessionHas('success');
        Queue::assertPushed(ExtractStreamEventsJob::class);
        Queue::assertPushed(DownloadVodJob::class);
    }

    public function test_a_late_retry_of_a_killed_transcription_does_not_redo_a_finished_one(): void
    {
        $stream = $this->stream(['transcription_status' => 'completed', 'transcription_stage' => 'completed']);
        $stream->transcriptSegments()->create(['start_time' => 1, 'end_time' => 2, 'text' => 'Hallo']);
        Http::fake();


        (new TranscribeStreamJob($stream->id))->handle(app(TranscriptionWorker::class), app(\App\Services\WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        Http::assertNothingSent();
        $this->assertSame(1, $stream->transcriptSegments()->count());
    }
}
