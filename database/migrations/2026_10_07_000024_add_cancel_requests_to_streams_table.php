<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Set when a running job is cancelled in the UI; the job sees it while working and stops (StreamJobCanceller).
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->timestamp('transcription_cancel_requested_at')->nullable();
            $table->timestamp('event_extraction_cancel_requested_at')->nullable();
            $table->timestamp('video_download_cancel_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->dropColumn(['transcription_cancel_requested_at', 'event_extraction_cancel_requested_at', 'video_download_cancel_requested_at']);
        });
    }
};
