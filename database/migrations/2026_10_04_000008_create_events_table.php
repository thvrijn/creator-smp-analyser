<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stream_id')->constrained('streams')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('description');
            $table->decimal('start_time', 12, 3);
            $table->decimal('end_time', 12, 3);
            $table->decimal('confidence', 4, 3);
            $table->timestamps();

            $table->index(['stream_id', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
