<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->string('video_download_status', 20)->default('pending')->after('video_file_size');
            $table->text('video_download_error')->nullable()->after('video_download_status');
            // Where the video file starts in the VOD: only the part when the server was open is downloaded.
            // VOD time = video_offset_seconds + a transcript segment's time.
            $table->float('video_offset_seconds')->default(0)->after('video_download_error');
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->dropColumn(['video_download_status', 'video_download_error', 'video_offset_seconds']);
        });
    }
};
