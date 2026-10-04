<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stream extends Model
{
    use HasFactory;

    protected $attributes = [
        'transcription_status' => 'pending',
        'transcription_stage' => 'not_started',
        'transcription_progress' => 0,
        'transcription_processed_seconds' => 0,
        'transcription_segment_count' => 0,
    ];

    protected $fillable = [
        'player_id',
        'title',
        'started_at',
        'ended_at',
        'source',
        'video_path',
        'video_original_filename',
        'video_mime_type',
        'video_file_size',
        'transcription_status',
        'transcription_error',
        'transcribed_at',
        'transcription_stage',
        'transcription_progress',
        'transcription_processed_seconds',
        'transcription_duration_seconds',
        'transcription_segment_count',
        'transcription_started_at',
        'transcription_eta_seconds',
        'event_extraction_status',
        'event_extraction_error',
        'event_extraction_started_at',
        'event_extraction_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'video_file_size' => 'integer',
            'transcribed_at' => 'datetime',
            'transcription_progress' => 'integer',
            'transcription_processed_seconds' => 'float',
            'transcription_duration_seconds' => 'float',
            'transcription_segment_count' => 'integer',
            'transcription_started_at' => 'datetime',
            'transcription_eta_seconds' => 'float',
            'event_extraction_started_at' => 'datetime',
            'event_extraction_completed_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function transcriptSegments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function estimatedTranscriptionEta(): ?float
    {
        if ($this->transcription_eta_seconds !== null) {
            return (float) $this->transcription_eta_seconds;
        }

        if ($this->transcription_status !== 'processing'
            || $this->transcription_started_at === null
            || $this->transcription_duration_seconds === null
            || $this->transcription_processed_seconds <= 0) {
            return null;
        }

        $elapsed = abs((float) now()->diffInSeconds($this->transcription_started_at));
        $rate = $elapsed > 0 ? $this->transcription_processed_seconds / $elapsed : 0;

        return $rate > 0
            ? max(0, ($this->transcription_duration_seconds - $this->transcription_processed_seconds) / $rate)
            : null;
    }
}
