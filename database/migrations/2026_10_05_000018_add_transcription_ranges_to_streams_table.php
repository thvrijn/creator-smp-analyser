<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            // The parts of the media file to transcribe, as [[start, end], ...] in seconds; null = the whole file.
            $table->json('transcription_ranges')->nullable()->after('video_offset_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->dropColumn('transcription_ranges');
        });
    }
};
