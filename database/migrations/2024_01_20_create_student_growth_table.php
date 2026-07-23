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
        Schema::create('student_growth', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('xp')->default(0)->comment('Total experience points');
            $table->integer('level')->default(1)->comment('Current level');
            $table->integer('xp_to_next_level')->default(1000)->comment('XP needed for next level');
            $table->integer('streaks')->default(0)->comment('Consecutive days active');
            $table->integer('total_quizzes_completed')->default(0);
            $table->integer('total_lessons_completed')->default(0);
            $table->float('average_score')->default(0)->comment('Average quiz score');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_growth');
    }
};
