<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Stream */
class StreamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'player' => ['id' => $this->player->id, 'name' => $this->player->name],
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'source' => $this->source,
            'video_path' => $this->video_path,
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
            'status' => $this->ended_at === null ? 'Live' : 'Finished',
        ];
    }
}
