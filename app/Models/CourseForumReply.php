<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\CourseForumThread;
use App\Models\User;

class CourseForumReply extends Model
{
    protected $table = 'course_forum_replies';

    protected $fillable = [
        'thread_id',
        'user_id',
        'answer',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(CourseForumThread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
