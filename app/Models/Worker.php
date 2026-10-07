<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A GPU machine (laptop, pc, Mac) that checks in with a heartbeat and runs transcriptions and event extractions. */
class Worker extends Model
{
    public const TASKS = ['transcribe', 'extract'];

    protected $fillable = [
        'name',
        'url',
        'backend',
        'gpu_name',
        'vram_mb',
        'capabilities',
        'priority',
        'enabled',
        'loaded_model',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'vram_mb' => 'integer',
            'priority' => 'integer',
            'enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function currentStream(): BelongsTo
    {
        return $this->belongsTo(Stream::class, 'current_stream_id');
    }

    /** Workers whose last heartbeat is recent enough. */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subSeconds(self::onlineSeconds()));
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gte(now()->subSeconds(self::onlineSeconds()));
    }

    public static function onlineSeconds(): int
    {
        return (int) config('services.workers.online_seconds', 45);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'online' => $this->isOnline(),
            'enabled' => $this->enabled,
            'backend' => $this->backend,
            'gpu_name' => $this->gpu_name,
            'vram_mb' => $this->vram_mb,
            'capabilities' => $this->capabilities,
            'loaded_model' => $this->loaded_model,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'current_task' => $this->current_task,
            'current_stream_id' => $this->current_stream_id,
        ];
    }
}
