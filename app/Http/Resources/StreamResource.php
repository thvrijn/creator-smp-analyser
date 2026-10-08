<?php

namespace App\Http\Resources;

use App\Models\Stream;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Stream */
class StreamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'player' => ['id' => $this->player->id, 'name' => $this->player->name, 'photo_url' => $this->player->photoUrl()],
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'source' => $this->source,
            'video_path' => $this->video_path,
            'video_mime_type' => $this->video_mime_type,
            'twitch_video_id' => $this->twitch_video_id,
            // While the stream is live the VOD is only a recording up to now, so link to the channel's live stream.
            'twitch_url' => $this->twitchUrl(),
            'video_file_size' => $this->video_file_size,
            'video_download_status' => $this->video_download_status,
            'video_download_progress' => $this->video_download_progress,
            'video_download_error' => $this->video_download_error,
            'video_offset_seconds' => $this->video_offset_seconds,
            'transcription_ranges' => $this->transcription_ranges,
            'transcription_status' => $this->transcription_status,
            'transcription_error' => $this->transcription_error,
            'transcription_stage' => $this->transcription_stage,
            'transcription_progress' => $this->transcription_progress,
            'transcription_processed_seconds' => $this->transcription_processed_seconds,
            'transcription_duration_seconds' => $this->transcription_duration_seconds,
            'transcription_segment_count' => $this->transcription_segment_count,
            'has_transcript' => $this->transcript_segments_count > 0,
            'transcription_started_at' => $this->transcription_started_at?->toIso8601String(),
            'transcription_eta_seconds' => $this->estimatedTranscriptionEta(),
            'event_extraction_status' => $this->event_extraction_status,
            'event_extraction_error' => $this->event_extraction_error,
            'event_extraction_progress' => $this->eventExtractionProgress(),
            'event_extraction_chunks_done' => $this->event_extraction_chunks_done,
            'event_extraction_chunks_total' => $this->event_extraction_chunks_total,
            'event_extraction_eta_seconds' => $this->estimatedEventExtractionEta(),
            'transcription_stalled' => $this->isStalled('transcription_status'),
            'event_extraction_stalled' => $this->isStalled('event_extraction_status'),
            'video_download_stalled' => $this->isStalled('video_download_status'),
            'worker_name' => $this->activeWorker?->name,
            // Cancelled in the UI, the running job stops within a few seconds.
            'transcription_cancelling' => $this->transcription_cancel_requested_at !== null,
            'event_extraction_cancelling' => $this->event_extraction_cancel_requested_at !== null,
            'video_download_cancelling' => $this->video_download_cancel_requested_at !== null,
            'status' => $this->ended_at === null ? 'Live' : 'Finished',
        ];
    }

    private function twitchUrl(): ?string
    {
        if ($this->twitch_video_id === null) {
            return null;
        }

        if ($this->ended_at === null && $this->player->twitch_login !== null) {
            return 'https://www.twitch.tv/'.$this->player->twitch_login;
        }

        return 'https://www.twitch.tv/videos/'.$this->twitch_video_id;
    }
}
