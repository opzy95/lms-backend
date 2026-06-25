<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('quiz_attempts')->onDelete('cascade');
            $table->foreignId('question_id')->constrained()->onDelete('cascade');
            $table->text('student_answer');
            $table->boolean('is_correct')->default(false);
            $table->integer('marks_awarded')->default(0); // for essay grading
            $table->text('feedback')->nullable(); // tutor feedback on essay
            $table->foreignId('graded_by')->nullable()->constrained('users'); // tutor who graded
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
        });
    }
    public function down() { Schema::dropIfExists('quiz_answers'); }
};