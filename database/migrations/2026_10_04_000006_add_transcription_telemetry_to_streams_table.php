<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->string('transcription_stage')->default('queued')->index();
            $table->unsignedTinyInteger('transcription_progress')->default(0);
            $table->double('transcription_processed_seconds')->default(0);
            $table->double('transcription_duration_seconds')->nullable();
            $table->unsignedInteger('transcription_segment_count')->default(0);
            $table->timestampTz('transcription_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->dropColumn([
                'transcription_stage',
                'transcription_progress',
                'transcription_processed_seconds',
                'transcription_duration_seconds',
                'transcription_segment_count',
                'transcription_started_at',
            ]);
        });
    }
};
