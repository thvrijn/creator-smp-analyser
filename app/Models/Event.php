<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'stream_id',
        'type',
        'title',
        'description',
        'start_time',
        'end_time',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'decimal:3',
            'end_time' => 'decimal:3',
            'confidence' => 'float',
        ];
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    public function transcriptSegments(): BelongsToMany
    {
        return $this->belongsToMany(TranscriptSegment::class, 'event_transcript_segment');
    }
}
