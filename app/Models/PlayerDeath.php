<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A death of a player on the server, with the game's death message (CreatorSmpDeathSeeder). */
class PlayerDeath extends Model
{
    protected $fillable = ['player_id', 'died_at', 'message', 'source_id'];

    protected function casts(): array
    {
        return ['died_at' => 'datetime'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
