<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'education_level',
        'is_approved',
        'title',
        'first_name',
        'last_name',
        'phone_number',
        'location',
        'biography',
        'school_name',
        'core_subjects',
        'avatar_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'core_subjects' => 'array',
        ];
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Get the enrollments for this user
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class, 'student_id');
    }

    /**
     * Get the tutor documents for this user
     */
    public function tutorDocuments(): HasMany
    {
        return $this->hasMany(TutorDocument::class);
    }

    /**
     * Check if tutor has minimum required documents
     */
    public function hasMinimumDocuments(): bool
    {
        return $this->tutorDocuments()->where('status', 'approved')->count() >= 2;
    }

    /**
     * Get approved documents count
     */
    public function approvedDocumentsCount(): int
    {
        return $this->tutorDocuments()->where('status', 'approved')->count();
    }

    /**
     * Get the student goals for this user
     */
    public function goals()
    {
        return $this->hasMany(StudentGoal::class);
    }

    /**
     * Get the growth/gamification data for this student
     */
    public function growth()
    {
        return $this->hasOne(StudentGrowth::class);
    }

    /**
     * Get all courses created by this tutor
     */
    public function tutorCourses(): HasMany
    {
        return $this->hasMany(Course::class, 'tutor_id');
    }
}
