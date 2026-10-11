<?php

namespace App\Services;

use App\Jobs\DownloadVodJob;
use App\Jobs\ExtractStreamEventsJob;
use App\Jobs\TranscribeStreamJob;
use App\Models\Stream;
use Illuminate\Support\Facades\Storage;

/**
 * Queues a stream's next step: fetching its VOD audio, transcribing, or analysing. Used by the buttons on a stream and
 * by "Te doen" on the dashboard, which queues a step for every stream that is ready for it. Each method returns null
 * when the job was queued, or the Dutch reason why not.
 */
class StreamPipeline
{
    public function download(Stream $stream): ?string
    {
        if ($stream->twitch_video_id === null) {
            return 'Deze stream is geen Twitch-VOD.';
        }
        if ($stream->ended_at === null) {
            return "Deze stream is nog live. Haal de audio op als hij voorbij is, en sync de VOD's dan eerst opnieuw.";
        }
        if ($stream->video_path !== null) {
            return 'Deze stream heeft al audio of video.';
        }
        if (in_array($stream->video_download_status, ['queued', 'processing'], true) && ! $stream->isStalled('video_download_status')) {
            return 'De audio wordt al opgehaald.';
        }

        $stream->forceFill(['video_download_status' => 'queued', 'video_download_progress' => 0, 'video_download_error' => null])->save();
        DownloadVodJob::dispatch($stream->id);

        return null;
    }

    public function transcribe(Stream $stream): ?string
    {
        if (blank($stream->video_path)) {
            return 'Deze stream heeft geen videobestand.';
        }
        if (in_array($stream->transcription_status, Stream::ACTIVE_STATUSES, true) && ! $stream->isStalled('transcription_status')) {
            return 'Deze stream staat al in de wachtrij of wordt al getranscribeerd.';
        }
        // A new transcript replaces the segments a running analysis is linking its events to.
        if (in_array($stream->event_extraction_status, Stream::ACTIVE_STATUSES, true) && ! $stream->isStalled('event_extraction_status')) {
            return 'Deze stream wordt nog geanalyseerd. Transcribeer opnieuw als de analyse klaar is.';
        }
        if (! Storage::disk(config('filesystems.default'))->exists($stream->video_path)) {
            return 'De streamvideo bestaat niet.';
        }

        try {
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
        } catch (\Throwable $exception) {
            $stream->forceFill([
                'transcription_status' => 'failed',
                'transcription_error' => 'De transcriptie kon niet aan de wachtrij worden toegevoegd: '.$exception->getMessage(),
            ])->save();

            return 'Transcriptie kon niet aan de wachtrij worden toegevoegd.';
        }

        return null;
    }

    public function extract(Stream $stream): ?string
    {
        if ($stream->transcription_status !== 'completed' || $stream->transcriptSegments()->doesntExist()) {
            return 'Complete transcriptie is nodig voordat events kunnen worden geëxtraheerd.';
        }
        if (in_array($stream->event_extraction_status, Stream::ACTIVE_STATUSES, true) && ! $stream->isStalled('event_extraction_status')) {
            return 'Event-extractie staat al in de wachtrij of is bezig voor deze stream.';
        }

        try {
            $stream->forceFill([
                'event_extraction_status' => 'queued',
                'event_extraction_error' => null,
                'event_extraction_chunks_done' => 0,
                'event_extraction_chunks_total' => null,
                'event_extraction_started_at' => null,
                'event_extraction_completed_at' => null,
            ])->save();
            ExtractStreamEventsJob::dispatch($stream->id);
        } catch (\Throwable $exception) {
            $stream->forceFill([
                'event_extraction_status' => 'failed',
                'event_extraction_error' => 'De event-extractie kon niet aan de wachtrij worden toegevoegd: '.$exception->getMessage(),
            ])->save();

            return 'Event-extractie kon niet aan de wachtrij worden toegevoegd.';
        }

        return null;
    }
}
