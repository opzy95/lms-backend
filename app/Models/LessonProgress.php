<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    protected $table = 'lesson_progress';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'status',
        'lesson_read',
        'quiz_completed',
        'quiz_score',
        'attempts',
    ];

    protected $casts = [
        'lesson_read' => 'boolean',
        'quiz_completed' => 'boolean',
        'quiz_score' => 'decimal:2',
        'attempts' => 'integer',
    ];

    /**
     * Relationships
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Helper Methods
     */

    /**
     * Mark lesson as started
     */
    public function markStarted(): void
    {
        if ($this->status === 'not_started') {
            $this->update(['status' => 'ongoing']);
        }
    }

    /**
     * Mark lesson content as read
     */
    public function markLessonRead(): void
    {
        $this->update(['lesson_read' => true]);

        // If quiz is already completed, mark entire lesson as finished
        if ($this->quiz_completed) {
            $this->update(['status' => 'finished']);
        }
    }

    /**
     * Mark quiz as completed and update status
     */
    public function markQuizCompleted(float $score): void
    {
        $this->update([
            'quiz_completed' => true,
            'quiz_score' => $score,
            'attempts' => $this->attempts + 1,
        ]);

        // Mark as finished if lesson was also read
        if ($this->lesson_read) {
            $this->update(['status' => 'finished']);
        } else {
            // Set to ongoing if not already finished
            if ($this->status !== 'finished') {
                $this->update(['status' => 'ongoing']);
            }
        }
    }

    /**
     * Check if lesson is fully completed
     */
    public function isFullyCompleted(): bool
    {
        return $this->status === 'finished' && $this->lesson_read && $this->quiz_completed;
    }

    /**
     * Get or create progress record for a student and lesson
     */
    public static function getOrCreate($user_id, $lesson_id): self
    {
        return self::firstOrCreate(
            ['user_id' => $user_id, 'lesson_id' => $lesson_id],
            ['status' => 'not_started']
        );
    }
}
