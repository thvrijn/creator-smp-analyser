<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Who a diarized speaker of a stream is, set by hand on the stream page: a player, or a free name (e.g. a guest).
    // A row with a player is a confirmed voice for that player (for matching voices later).
    public function up(): void
    {
        Schema::create('stream_speakers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stream_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('speaker');
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['stream_id', 'speaker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_speakers');
    }
};
