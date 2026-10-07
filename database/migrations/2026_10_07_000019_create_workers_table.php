<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GPU machines that check in with a heartbeat; the app hands each one task at a time.
        Schema::create('workers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('url');
            $table->string('backend')->nullable(); // cuda, mlx or cpu
            $table->string('gpu_name')->nullable();
            $table->unsignedInteger('vram_mb')->nullable();
            $table->json('capabilities'); // transcribe, extract
            $table->integer('priority')->default(0);
            $table->boolean('enabled')->default(true);
            $table->string('loaded_model')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // The task the app claimed this worker for; cleared when the job ends.
            $table->string('current_task')->nullable();
            $table->foreignId('current_stream_id')->nullable()->constrained('streams')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
