<?php

namespace App\Console\Commands;

use App\Jobs\TranscribeStreamJob;
use App\Models\Stream;
use App\Services\TranscriptionWorker;
use App\Services\WorkerPool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TranscribeStream extends Command
{
    protected $signature = 'stream:transcribe {stream : The stream ID to transcribe} {--sync : Run synchronously for debugging}';
    protected $description = 'Queue transcription for an existing stream video';

    public function handle(): int
    {
        $stream = Stream::find($this->argument('stream'));
        if ($stream === null) {
            $this->error('Stream not found.');
            return self::FAILURE;
        }
        if (blank($stream->video_path)) {
            $this->error("Stream #{$stream->id} has no video file.");
            return self::FAILURE;
        }
        if (! Storage::disk(config('filesystems.default'))->exists($stream->video_path)) {
            $this->error("The stream video does not exist: {$stream->video_path}");
            return self::FAILURE;
        }
        if (in_array($stream->transcription_status, ['queued', 'waiting', 'processing'], true)) {
            $this->warn("Stream #{$stream->id} is already queued or being transcribed.");
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $pool = app(WorkerPool::class);
            $worker = $pool->claim('transcribe', $stream);
            if ($worker === null) {
                $this->error('No free worker online (see: php artisan workers:list).');
                return self::FAILURE;
            }
            $this->line("Transcribing on worker {$worker->name} ({$worker->url})...");
            try {
                $segments = app(TranscriptionWorker::class)->transcribe($worker, $stream);
                DB::transaction(function () use ($stream, $segments): void {
                    $stream->transcriptSegments()->delete();
                    $stream->transcriptSegments()->createMany($segments);
                    $stream->forceFill([
                        'transcription_status' => 'completed',
                        'transcription_error' => null,
                        'transcribed_at' => now(),
                        'transcription_stage' => 'completed',
                        'transcription_progress' => 100,
                        'transcription_segment_count' => count($segments),
                        'transcription_eta_seconds' => 0,
                    ])->save();
                });
            } catch (\Throwable $exception) {
                $this->error($exception->getMessage());
                return self::FAILURE;
            } finally {
                $pool->release($worker);
            }
            $this->info('Done: '.count($segments).' segments saved.');
            return self::SUCCESS;
        }

        $stream->forceFill([
            'transcription_status' => 'queued',
            'transcription_error' => null,
            'transcribed_at' => null,
            'transcription_stage' => 'queued',
            'transcription_progress' => 0,
            'transcription_processed_seconds' => 0,
            'transcription_duration_seconds' => null,
            'transcription_segment_count' => 0,
            'transcription_started_at' => null,
            'transcription_eta_seconds' => null,
        ])->save();
        TranscribeStreamJob::dispatch($stream->id);
        $this->info("Transcription for stream #{$stream->id} was added to the queue.");
        return self::SUCCESS;
    }
}
