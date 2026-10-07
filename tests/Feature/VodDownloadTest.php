<?php

namespace Tests\Feature;

use App\Jobs\DownloadVodJob;
use App\Models\Player;
use App\Models\Stream;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VodDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake();
        config(['services.twitch.download_gb_per_hour' => 0]);
    }

    private function vod(): Stream
    {
        return Player::create(['name' => 'Duncan', 'twitch_login' => 'duncan'])->streams()->create([
            'title' => 'Dag 1', 'source' => 'twitch', 'twitch_video_id' => '3001',
            'started_at' => '2026-10-04 16:46:39', 'ended_at' => '2026-10-04 22:08:02',
        ]);
    }

    /**
     * Stands in for yt-dlp (writes the whole audio to the -o template and prints the chapters, then the video codec and path,
     * or fails after a partial file) and for ffmpeg (writes the cut to its last argument).
     */
    private function fakeProcesses(?array $chapters = null, bool $ytDlpFails = false): void
    {
        Process::fake(function (PendingProcess $process) use ($chapters, $ytDlpFails) {
            if ($process->command[0] === 'ffmpeg') {
                file_put_contents(end($process->command), 'cut audio');

                return Process::result();
            }

            $file = str_replace('%(ext)s', 'mp4', $process->command[array_search('-o', $process->command) + 1]);
            file_put_contents($file, 'audio');

            return $ytDlpFails
                ? Process::result(errorOutput: "progress 3/120\nERROR: [twitch:vod] 3001: This video is only available for subscribers\nprogress 4/120\n", exitCode: 1)
                : Process::result(output: "progress 1/2\n".json_encode($chapters)."\nprogress 2/2\nnone ".$file."\n");
        });
    }

    public function test_only_the_creator_smp_part_during_opening_hours_is_marked_for_transcription(): void
    {
        $stream = $this->vod(); // 18:46:39-00:08:02 Dutch time
        $this->fakeProcesses([
            ['start_time' => 0, 'end_time' => 420, 'title' => 'Just Chatting'],
            ['start_time' => 420, 'end_time' => 19283, 'title' => 'CreatorSMP'],
        ]);

        DownloadVodJob::dispatchSync($this->markQueued($stream, 'video_download')->id);

        // From the CreatorSMP chapter (420 s) to the server closing at midnight (22:00 UTC, 18801 s); the file stays whole.
        Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'yt-dlp' && in_array('ba/worst', $process->command, true));
        Process::assertDidntRun(fn (PendingProcess $process) => $process->command[0] === 'ffmpeg');
        $stream->refresh();
        $this->assertSame('completed', $stream->video_download_status);
        $this->assertSame("streams/{$stream->id}/video/twitch-3001.mp4", $stream->video_path);
        $this->assertSame('audio/mp4', $stream->video_mime_type);
        $this->assertSame([[420, 18801]], $stream->transcription_ranges);
        $this->assertSame(0.0, $stream->video_offset_seconds);
        $this->assertSame(100, $stream->video_download_progress);
        Storage::assertExists($stream->video_path);
        Storage::assertMissing("streams/{$stream->id}/video/twitch-3001.full.mp4");
    }

    public function test_another_game_in_between_is_left_out(): void
    {
        $stream = $this->vod();
        $this->fakeProcesses([
            ['start_time' => 0, 'end_time' => 793, 'title' => 'CreatorSMP'],
            ['start_time' => 793, 'end_time' => 10000, 'title' => 'EA Sports FC 27'],
            ['start_time' => 10000, 'end_time' => 19283, 'title' => 'CreatorSMP'],
        ]);

        DownloadVodJob::dispatchSync($this->markQueued($stream, 'video_download')->id);

        $this->assertSame([[0, 793], [10000, 18801]], $stream->fresh()->transcription_ranges);
    }

    public function test_without_chapters_only_the_opening_hours_count(): void
    {
        $stream = $this->vod();
        $this->fakeProcesses(null);

        DownloadVodJob::dispatchSync($this->markQueued($stream, 'video_download')->id);

        $this->assertSame([[0, 18801]], $stream->fresh()->transcription_ranges);
    }

    public function test_a_vod_that_is_all_creator_smp_during_opening_hours_is_transcribed_whole(): void
    {
        $stream = $this->vod();
        $stream->update(['ended_at' => '2026-10-04 21:00:00']);
        $this->fakeProcesses([['start_time' => 0, 'end_time' => 15201, 'title' => 'CreatorSMP']]);

        DownloadVodJob::dispatchSync($this->markQueued($stream, 'video_download')->id);

        $this->assertNull($stream->fresh()->transcription_ranges);
    }

    public function test_a_vod_without_creator_smp_is_not_kept(): void
    {
        $stream = $this->vod();
        $this->fakeProcesses([['start_time' => 0, 'end_time' => 19283, 'title' => 'Just Chatting']]);

        DownloadVodJob::dispatchSync($this->markQueued($stream, 'video_download')->id);

        $stream->refresh();
        $this->assertSame('failed', $stream->video_download_status);
        $this->assertSame('Deze VOD heeft geen deel in de categorie CreatorSMP tussen 14:00 en 00:00.', $stream->video_download_error);
        $this->assertNull($stream->video_path);
        Storage::assertMissing("streams/{$stream->id}/video/twitch-3001.full.mp4");
    }

    public function test_a_failed_download_is_reported_and_its_partial_file_removed(): void
    {
        $stream = $this->vod();
        $this->fakeProcesses(ytDlpFails: true);

        DownloadVodJob::dispatchSync($this->markQueued($stream, 'video_download')->id);

        $stream->refresh();
        $this->assertSame('failed', $stream->video_download_status);
        $this->assertSame('Downloaden is mislukt: ERROR: [twitch:vod] 3001: This video is only available for subscribers', $stream->video_download_error);
        $this->assertNull($stream->video_path);
        Storage::assertMissing("streams/{$stream->id}/video/twitch-3001.full.mp4");
    }

    public function test_progress_lines_from_yt_dlp_become_a_percentage(): void
    {
        $this->assertSame(0, DownloadVodJob::progressPercent('progress 1/1100'));
        $this->assertSame(45, DownloadVodJob::progressPercent("progress 500/1100\n"));
        $this->assertSame(99, DownloadVodJob::progressPercent('progress 1100/1100')); // 100 once the file is stored
        $this->assertNull(DownloadVodJob::progressPercent('progress NA/NA'));
        $this->assertNull(DownloadVodJob::progressPercent('none /storage/streams/2/video/twitch-1.full.mp4'));
    }

    public function test_a_download_that_does_not_fit_on_the_disk_is_not_started(): void
    {
        config(['services.twitch.download_gb_per_hour' => 1e9]);
        $stream = $this->vod();
        Process::fake();

        DownloadVodJob::dispatchSync($this->markQueued($stream, 'video_download')->id);

        $this->assertStringStartsWith('Niet genoeg schijfruimte', $stream->fresh()->video_download_error);
        Process::assertNothingRan();
    }

    public function test_the_button_queues_a_download_only_for_a_twitch_vod_without_video(): void
    {
        Queue::fake();
        $stream = $this->vod();
        $upload = $stream->player->streams()->create(['title' => 'Upload', 'source' => 'lokaal', 'started_at' => now()]);

        $this->post("/streams/{$stream->id}/download-audio")->assertSessionHas('success');
        $this->assertSame('queued', $stream->fresh()->video_download_status);
        Queue::assertPushed(DownloadVodJob::class, fn (DownloadVodJob $job) => $job->streamId === $stream->id);

        $this->post("/streams/{$stream->id}/download-audio")->assertSessionHas('error', 'De audio wordt al opgehaald.');
        $this->post("/streams/{$upload->id}/download-audio")->assertSessionHas('error', 'Deze stream is geen Twitch-VOD.');
        $live = $stream->player->streams()->create(['title' => 'Dag 2', 'source' => 'twitch', 'twitch_video_id' => '4001', 'started_at' => now()]);
        $this->post("/streams/{$live->id}/download-audio")->assertSessionHas('error', "Deze stream is nog live. Haal de audio op als hij voorbij is, en sync de VOD's dan eerst opnieuw.");
        Queue::assertPushed(DownloadVodJob::class, 1);
    }
}
