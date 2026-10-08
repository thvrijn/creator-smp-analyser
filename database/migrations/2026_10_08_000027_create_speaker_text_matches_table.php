<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A speaker of one stream who says the same sentences, at the same server time, as the streamer of another stream
    // (App\Services\SpeakerTextMatches): strong evidence that the speaker is that streamer.
    public function up(): void
    {
        Schema::create('speaker_text_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stream_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('speaker');
            $table->foreignId('other_stream_id')->constrained('streams')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            // Sentences of the speaker that were also said in the other stream, out of those that could be compared.
            $table->unsignedInteger('hits');
            $table->unsignedInteger('compared');
            $table->timestamps();

            $table->unique(['stream_id', 'speaker', 'other_stream_id']);
            $table->index('other_stream_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speaker_text_matches');
    }
};
