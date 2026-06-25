<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    protected $fillable = [
        'quiz_id',
        'student_id',
        'started_at',
        'submitted_at',
        'score',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function answers() { return $this->hasMany(QuizAnswer::class, 'attempt_id'); }
    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function quiz(): BelongsTo { return $this->belongsTo(Quiz::class); }
}
