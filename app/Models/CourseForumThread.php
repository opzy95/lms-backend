<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Models\CourseForumReply;

class CourseForumThread extends Model
{
    protected $table = 'course_forum_threads';

    protected $fillable = [
        'course_id',
        'lesson_id',
        'user_id',
        'question',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CourseForumReply::class, 'thread_id');
    }
}
