<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TranscriptSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'stream_id',
        'start_time',
        'end_time',
        'text',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'decimal:3',
            'end_time' => 'decimal:3',
        ];
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_transcript_segment');
    }
}
