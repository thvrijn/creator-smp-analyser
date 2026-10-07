<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcript_segments', function (Blueprint $table): void {
            // Who speaks (diarization): 0 speaks the most in the stream, usually the streamer; null = unknown.
            $table->unsignedSmallInteger('speaker')->nullable()->after('text');
        });
        Schema::table('streams', function (Blueprint $table): void {
            // Per speaker: [{speaker, seconds, embedding}], the voice embeddings for matching voices across streams later.
            $table->json('transcription_speakers')->nullable()->after('transcription_ranges');
        });
    }

    public function down(): void
    {
        Schema::table('transcript_segments', function (Blueprint $table): void {
            $table->dropColumn('speaker');
        });
        Schema::table('streams', function (Blueprint $table): void {
            $table->dropColumn('transcription_speakers');
        });
    }
};
