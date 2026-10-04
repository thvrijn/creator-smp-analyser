<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->string('transcription_status')->default('pending')->index();
            $table->text('transcription_error')->nullable();
            $table->timestampTz('transcribed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->dropColumn(['transcription_status', 'transcription_error', 'transcribed_at']);
        });
    }
};
