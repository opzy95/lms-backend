<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiveClass extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'tutor_id', 
        'title',
        'description',
        'room_name',
        'start_time',
        'end_time',
        'status'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function tutor()
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function attendances()
    {
        return $this->hasMany(LiveAttendance::class);
    }

    /**
     * Get students currently in the live class
     */
    public function getActiveStudents()
    {
        return $this->attendances()
            ->whereNull('left_at')
            ->with('student:id,name,email')
            ->get();
    }

    /**
     * Get all students who attended this class
     */
    public function getAttendedStudents()
    {
        return $this->attendances()
            ->whereNotNull('left_at')
            ->with('student:id,name,email')
            ->get();
    }

    /**
     * Check if class is ongoing
     */
    public function getIsLiveAttribute()
    {
        return $this->status === 'live' && now()->between($this->start_time, $this->end_time ?? now()->addHours(2));
    }

    /**
     * Check if student is currently in class
     */
    public function isStudentInClass($userId)
    {
        return $this->attendances()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }
}
