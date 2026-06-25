<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('course_forum_threads', function (Blueprint $table) {
        $table->id();
        $table->foreignId('course_id')->constrained()->onDelete('cascade');
        $table->foreignId('lesson_id')->constrained()->onDelete('cascade'); // which lesson the Q is about
        $table->foreignId('user_id')->constrained()->onDelete('cascade'); // who asked
        $table->text('question'); // the question
        $table->timestamps();
    });
}

public function down(): void
    {
        Schema::dropIfExists('course_forum_threads');
    }
};
