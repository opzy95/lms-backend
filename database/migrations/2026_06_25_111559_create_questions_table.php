<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->onDelete('cascade');
            $table->text('question_text');
            $table->enum('type', ['mcq', 'boolean', 'essay']);
            $table->json('options')->nullable(); // ["A","B","C"] or ["Yes","No"]
            $table->string('correct_answer')->nullable(); // null for essay
            $table->integer('points')->default(1);
            $table->timestamps();
        });
    }
    public function down() { Schema::dropIfExists('questions'); }
};