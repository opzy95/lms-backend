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
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();

            // Relationships
            $table->foreignId('course_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('tutor_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // Lesson details
            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('type', ['video', 'text', 'file', 'quiz']);

            $table->string('video_url')->nullable();
            $table->longText('content')->nullable();

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();

            $table->integer('order')->default(0);

            // Lesson status
            $table->enum('status', ['DRAFT', 'PUBLISHED'])
                  ->default('PUBLISHED');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};