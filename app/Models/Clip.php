<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A chosen moment of a stream for the video, in seconds from the stream (VOD) start. */
class Clip extends Model
{
    protected $fillable = [
        'event_id',
        'title',
        'start_seconds',
        'end_seconds',
    ];

    protected function casts(): array
    {
        return [
            'start_seconds' => 'float',
            'end_seconds' => 'float',
        ];
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'title' => $this->title,
            'start_seconds' => $this->start_seconds,
            'end_seconds' => $this->end_seconds,
        ];
    }
}
