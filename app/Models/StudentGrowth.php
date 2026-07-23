<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGrowth extends Model
{
    protected $table = 'student_growth';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'xp',
        'level',
        'xp_to_next_level',
        'streaks',
        'total_quizzes_completed',
        'total_lessons_completed',
        'average_score',
        'last_activity_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
    ];

    /**
     * XP required for each level (progressive increase)
     */
    public static function getXpForLevel(int $level): int
    {
        return 1000 * $level * ($level - 1) / 2 + 1000;
    }

    /**
     * Get the user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Add XP and handle level ups
     */
    public function addXp(int $xp): void
    {
        $this->xp += $xp;
        $this->last_activity_at = now();
        
        // Check for level ups
        while ($this->xp >= $this->xp_to_next_level) {
            $this->xp -= $this->xp_to_next_level;
            $this->level++;
            $this->xp_to_next_level = self::getXpForLevel($this->level);
        }
        
        $this->save();
    }

    /**
     * Add quiz completion
     */
    public function addQuizCompletion(float $score): void
    {
        $this->total_quizzes_completed++;
        
        // Update average score
        $totalScore = ($this->average_score * ($this->total_quizzes_completed - 1)) + $score;
        $this->average_score = $totalScore / $this->total_quizzes_completed;
        
        // Award XP based on score
        $xpReward = intval($score * 10); // 0-1000 XP based on score
        $this->addXp($xpReward);
    }

    /**
     * Add lesson completion
     */
    public function addLessonCompletion(): void
    {
        $this->total_lessons_completed++;
        $this->addXp(100); // 100 XP per lesson
    }

    /**
     * Update streak
     */
    public function updateStreak(): void
    {
        $this->streaks++;
        $this->save();
    }

    /**
     * Reset streak
     */
    public function resetStreak(): void
    {
        $this->streaks = 0;
        $this->save();
    }

    /**
     * Get progress to next level (0-100%)
     */
    public function getProgressToNextLevel(): int
    {
        $xpInCurrentLevel = $this->xp;
        $totalXpForLevel = $this->xp_to_next_level;
        
        if ($totalXpForLevel == 0) {
            return 0;
        }
        
        return round(($xpInCurrentLevel / $totalXpForLevel) * 100);
    }
}
