<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('lesson_id');
            $table->enum('status', ['not_started', 'ongoing', 'finished'])->default('not_started');
            $table->boolean('lesson_read')->default(false);
            $table->boolean('quiz_completed')->default(false);
            $table->decimal('quiz_score', 5, 2)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('lesson_id')->references('id')->on('lessons')->onDelete('cascade');

            // Unique constraint to ensure one record per student per lesson
            $table->unique(['user_id', 'lesson_id']);

            // Indexes for faster queries
            $table->index('user_id');
            $table->index('lesson_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
