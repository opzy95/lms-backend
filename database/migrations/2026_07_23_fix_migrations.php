<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Mark problematic migrations as run if they're not already
        $migrations = [
            '2026_06_25_111633_create_quiz_attemps_table',
            '2026_06_25_124234_create_quiz_answers_table',
            '2026_06_25_140500_create_course_forum_threads_table',
            '2026_06_25_140515_create_course_forum_replies_table',
            '2026_07_06_add_enrolled_at_to_enrollments_table',
            '2026_07_08_000000_add_grade_and_subject_to_lessons_table',
            '2026_07_09_130207_create_lesson_progress_table',
            '2026_07_16_create_live_attendances_table',
        ];

        foreach ($migrations as $migration) {
            // Check if migration exists
            $exists = DB::table('migrations')->where('migration', $migration)->exists();
            
            if (!$exists) {
                DB::table('migrations')->insert([
                    'migration' => $migration,
                    'batch' => DB::table('migrations')->max('batch') + 1,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration cannot be rolled back
    }
};
