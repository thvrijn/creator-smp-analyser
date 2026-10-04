<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class);
    }

    public function events(): HasManyThrough
    {
        return $this->hasManyThrough(Event::class, Stream::class);
    }

    /** Adds the per-player stream statistics shown on the dashboard cards and the player page. */
    public function scopeWithStreamStats(Builder $query): Builder
    {
        return $query
            ->withCount([
                'streams',
                'streams as transcribed_streams_count' => fn (Builder $streams) => $streams->where('transcription_status', 'completed'),
                'streams as active_streams_count' => fn (Builder $streams) => $streams->where(fn (Builder $active) => $active
                    ->whereIn('transcription_status', ['queued', 'processing'])
                    ->orWhereIn('event_extraction_status', ['queued', 'processing'])),
                'events',
            ])
            ->withMax('streams', 'started_at');
    }

    /** @return array<string, mixed> */
    public function statsPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'streams_count' => (int) $this->streams_count,
            'transcribed_streams_count' => (int) $this->transcribed_streams_count,
            'active_streams_count' => (int) $this->active_streams_count,
            'events_count' => (int) $this->events_count,
            'last_stream_at' => $this->streams_max_started_at === null ? null : Carbon::parse($this->streams_max_started_at)->toIso8601String(),
        ];
    }
}
