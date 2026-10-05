<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stream_id')->constrained()->cascadeOnDelete();
            // Re-analysing a stream replaces its events; the clip stays.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            // Seconds from the stream (VOD) start: transcript time + streams.video_offset_seconds.
            $table->float('start_seconds');
            $table->float('end_seconds');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clips');
    }
};
