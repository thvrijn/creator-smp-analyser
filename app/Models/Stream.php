<?php

namespace App\Models;

use App\Services\VoiceProfiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'twitch_video_id',
        'video_path',
        'video_original_filename',
        'video_mime_type',
        'video_file_size',
        'video_download_status',
        'video_download_progress',
        'video_download_error',
        'video_offset_seconds',
        'transcription_ranges',
        'transcription_speakers',
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
        'event_extraction_chunks_done',
        'event_extraction_chunks_total',
        'event_extraction_started_at',
        'event_extraction_completed_at',
    ];

    protected static function booted(): void
    {
        // The voice profiles (VoiceProfiles) are built from streams' voices and their players.
        static::saved(fn (Stream $stream) => $stream->wasChanged('transcription_speakers') ? VoiceProfiles::forget() : null);
        static::deleted(fn () => VoiceProfiles::forget());
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'video_file_size' => 'integer',
            'video_offset_seconds' => 'float',
            'transcription_ranges' => 'array',
            'transcription_speakers' => 'array',
            'transcribed_at' => 'datetime',
            'transcription_progress' => 'integer',
            'transcription_processed_seconds' => 'float',
            'transcription_duration_seconds' => 'float',
            'transcription_segment_count' => 'integer',
            'transcription_started_at' => 'datetime',
            'transcription_eta_seconds' => 'float',
            'event_extraction_chunks_done' => 'integer',
            'event_extraction_chunks_total' => 'integer',
            'event_extraction_started_at' => 'datetime',
            'event_extraction_completed_at' => 'datetime',
            'transcription_cancel_requested_at' => 'datetime',
            'event_extraction_cancel_requested_at' => 'datetime',
            'video_download_cancel_requested_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** A job saves progress at least every few seconds; this long without any means it was killed (a restart or crash). */
    public const STALLED_AFTER_MINUTES = 10;

    /**
     * The statuses a queued job may start from: queued, waiting for a worker, or its own retry after a failure.
     * Anything else (e.g. back to pending or completed after a cancel) means the job is no longer wanted.
     */
    public const STARTABLE_STATUSES = ['queued', 'waiting', 'failed'];

    /**
     * Whether the job behind this status column is on "processing" or "waiting" (for a worker) but no longer running,
     * so it may be started again. A waiting job touches the stream on every check for a free worker.
     */
    public function isStalled(string $statusColumn): bool
    {
        return in_array($this->{$statusColumn}, ['processing', 'waiting'], true)
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subMinutes(self::STALLED_AFTER_MINUTES));
    }

    /** The worker that is transcribing or analysing this stream right now. */
    public function activeWorker(): HasOne
    {
        return $this->hasOne(Worker::class, 'current_stream_id');
    }

    /** Names given by hand to this stream's diarized speakers. */
    public function speakerNames(): HasMany
    {
        return $this->hasMany(StreamSpeaker::class);
    }

    public function transcriptSegments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class);
    }

    /** Percentage of the analysis' chunks done; null before the job knows how many there are. */
    public function eventExtractionProgress(): ?int
    {
        if (! $this->event_extraction_chunks_total) {
            return null;
        }

        return (int) min(100, floor($this->event_extraction_chunks_done / $this->event_extraction_chunks_total * 100));
    }

    /** Seconds the analysis still needs at its average pace per chunk so far (including the model load). */
    public function estimatedEventExtractionEta(): ?float
    {
        if ($this->event_extraction_status !== 'processing'
            || $this->event_extraction_started_at === null
            || ! $this->event_extraction_chunks_total
            || $this->event_extraction_chunks_done <= 0) {
            return null;
        }

        $elapsed = abs((float) now()->diffInSeconds($this->event_extraction_started_at));
        $remaining = max(0, $this->event_extraction_chunks_total - $this->event_extraction_chunks_done);

        return round($elapsed / $this->event_extraction_chunks_done * $remaining);
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
