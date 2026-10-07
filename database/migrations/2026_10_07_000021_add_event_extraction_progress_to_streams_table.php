<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            // Progress of an analysis: transcript chunks sent to the worker so far, out of the total.
            $table->unsignedInteger('event_extraction_chunks_done')->default(0)->after('event_extraction_error');
            $table->unsignedInteger('event_extraction_chunks_total')->nullable()->after('event_extraction_chunks_done');
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->dropColumn(['event_extraction_chunks_done', 'event_extraction_chunks_total']);
        });
    }
};
