<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGoal extends Model
{
    protected $table = 'student_goals';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'target_date',
        'progress',
        'category',
    ];

    protected $casts = [
        'target_date' => 'datetime',
    ];

    /**
     * Get the user who created this goal
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if goal is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if goal is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Mark goal as completed
     */
    public function markCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'progress' => 100,
        ]);
    }

    /**
     * Update progress
     */
    public function updateProgress(int $progress): void
    {
        $progress = max(0, min(100, $progress)); // Ensure between 0-100
        $this->update(['progress' => $progress]);
        
        // Auto-complete if progress reaches 100%
        if ($progress >= 100) {
            $this->markCompleted();
        }
    }
}
