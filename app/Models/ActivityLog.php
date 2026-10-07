<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id', 'username', 'action', 'description', 'subject_type', 'subject_id', 'subject_label',
        'result', 'succeeded', 'count', 'ip_address',
    ];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean', 'count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
