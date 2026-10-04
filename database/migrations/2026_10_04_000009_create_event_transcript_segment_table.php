<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_transcript_segment', function (Blueprint $table): void {
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('transcript_segment_id')->constrained('transcript_segments')->cascadeOnDelete();
            $table->primary(['event_id', 'transcript_segment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_transcript_segment');
    }
};
