<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    protected $fillable = [
        'lesson_id',
        'title',
        'duration_minutes',
        'created_by',
    ];

    public function questions() { return $this->hasMany(Question::class); }
    public function lesson() { return $this->belongsTo(Lesson::class); }
}
