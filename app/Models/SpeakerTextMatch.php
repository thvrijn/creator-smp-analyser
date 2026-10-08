<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A speaker of a stream who says the same sentences as the streamer of another stream (SpeakerTextMatches). */
class SpeakerTextMatch extends Model
{
    protected $fillable = ['stream_id', 'speaker', 'other_stream_id', 'player_id', 'hits', 'compared'];

    protected function casts(): array
    {
        return ['speaker' => 'integer', 'player_id' => 'integer', 'other_stream_id' => 'integer', 'hits' => 'integer', 'compared' => 'integer'];
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    public function otherStream(): BelongsTo
    {
        return $this->belongsTo(Stream::class, 'other_stream_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
