<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A transcript line corrected by hand keeps what speech recognition wrote, to show it and to put it back.
    public function up(): void
    {
        Schema::table('transcript_segments', function (Blueprint $table): void {
            $table->text('original_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transcript_segments', function (Blueprint $table): void {
            $table->dropColumn('original_text');
        });
    }
};
