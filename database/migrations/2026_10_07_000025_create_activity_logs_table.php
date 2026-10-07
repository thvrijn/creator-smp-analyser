<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Who did what in the app (only the admin can read it). The username and subject name are copied, so an entry
    // still reads well after the account, stream or player is gone.
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username')->nullable();
            $table->string('action');
            $table->string('description');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->text('result')->nullable();
            $table->boolean('succeeded')->default(true);
            $table->unsignedInteger('count')->default(1);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
