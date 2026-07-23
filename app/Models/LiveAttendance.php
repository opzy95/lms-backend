<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiveAttendance extends Model
{
    use HasFactory;

    protected $table = 'live_attendances';

    protected $fillable = [
        'live_class_id',
        'user_id',
        'joined_at',
        'left_at',
        'duration_minutes'
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function liveClass()
    {
        return $this->belongsTo(LiveClass::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Calculate attendance duration in minutes
     */
    public function calculateDuration()
    {
        if ($this->left_at) {
            return $this->left_at->diffInMinutes($this->joined_at);
        }
        return null;
    }

    /**
     * Mark student as left from class
     */
    public function markAsLeft()
    {
        $this->update([
            'left_at' => now(),
            'duration_minutes' => now()->diffInMinutes($this->joined_at)
        ]);
    }
}
