<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            $table->string('video_path')->nullable();
            $table->string('video_original_filename')->nullable();
            $table->string('video_mime_type')->nullable();
            $table->unsignedBigInteger('video_file_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            $table->dropColumn([
                'video_path',
                'video_original_filename',
                'video_mime_type',
                'video_file_size',
            ]);
        });
    }
};
