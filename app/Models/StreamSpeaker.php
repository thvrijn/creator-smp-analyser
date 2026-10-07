<?php

namespace App\Models;

use App\Services\VoiceProfiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The name given by hand to a diarized speaker (transcript_segments.speaker) of one stream. */
class StreamSpeaker extends Model
{
    protected $fillable = ['stream_id', 'speaker', 'player_id', 'label'];

    protected static function booted(): void
    {
        // A name by hand confirms or changes a voice in the profiles.
        static::saved(fn () => VoiceProfiles::forget());
        static::deleted(fn () => VoiceProfiles::forget());
    }

    protected function casts(): array
    {
        return ['speaker' => 'integer', 'player_id' => 'integer'];
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
