<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->string('event_extraction_status')->default('pending')->index();
            $table->text('event_extraction_error')->nullable();
            $table->timestampTz('event_extraction_started_at')->nullable();
            $table->timestampTz('event_extraction_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table): void {
            $table->dropColumn([
                'event_extraction_status',
                'event_extraction_error',
                'event_extraction_started_at',
                'event_extraction_completed_at',
            ]);
        });
    }
};
