<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use App\Models\StudentGoal;
use App\Models\StudentGrowth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class StudentController extends Controller
{
    /**
     * GET /student/dashboard
     * All courses student is enrolled in + progress
     */
    public function dashboard()
    {
        $user_id = auth()->id();

        $enrollments = Enrollment::with(['course:id,title,tutor_id', 'course.tutor:id,name'])
            ->where('user_id', $user_id)
            ->get();

        if ($enrollments->isEmpty()) {
            return response()->json(['message' => 'No courses enrolled yet', 'courses' => []]);
        }

        $courseIds = $enrollments->pluck('course.id');

        $totalQuizzes = Lesson::whereIn('course_id', $courseIds)
            ->whereHas('quiz')
            ->selectRaw('course_id, count(*) as total')
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        $gradedQuizzes = QuizAttempt::where('student_id', $user_id)
            ->where('status', 'graded')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('lessons', 'lessons.id', '=', 'quizzes.lesson_id')
            ->whereIn('lessons.course_id', $courseIds)
            ->selectRaw('lessons.course_id, count(*) as total')
            ->groupBy('lessons.course_id')
            ->pluck('total', 'course_id');

        $data = $enrollments->map(function ($enroll) use ($totalQuizzes, $gradedQuizzes) {
            $course = $enroll->course;
            $total = $totalQuizzes[$course->id] ?? 0;
            $graded = $gradedQuizzes[$course->id] ?? 0;

            return [
                'course_id' => $course->id,
                'title' => $course->title,
                'tutor' => $course->tutor->name,
                'enrolled_at' => $enroll->enrolled_at,
                'total_quizzes' => $total,
                'completed_quizzes' => $graded,
                'progress_percent' => $total > 0 ? round(($graded / $total) * 100) : 0
            ];
        });

        return response()->json(['courses' => $data]);
    }

    /**
     * GET /student/enrolled-courses
     * Get list of all courses student has enrolled in
     */
    public function enrolledCourses()
    {
        $user_id = auth()->id();

        $enrollments = Enrollment::with([
            'course:id,title,description,price,type,tutor_id,created_at',
            'course.tutor:id,name,title'
        ])
            ->where('user_id', $user_id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        if ($enrollments->isEmpty()) {
            return response()->json([
                'message' => 'No courses enrolled yet',
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'per_page' => 20,
                    'current_page' => 1,
                    'last_page' => 1,
                ]
            ]);
        }

        $data = $enrollments->getCollection()->map(function ($enrollment) {
            return [
                'enrollment_id' => $enrollment->id,
                'course_id' => $enrollment->course->id,
                'title' => $enrollment->course->title,
                'description' => $enrollment->course->description,
                'price' => $enrollment->course->price,
                'type' => $enrollment->course->type,
                'tutor' => [
                    'id' => $enrollment->course->tutor->id,
                    'name' => $enrollment->course->tutor->name,
                    'title' => $enrollment->course->tutor->title,
                ],
                'enrolled_at' => $enrollment->enrolled_at,
                'created_at' => $enrollment->created_at,
            ];
        });

        return response()->json([
            'message' => 'Enrolled courses retrieved successfully',
            'data' => $data,
            'pagination' => [
                'total' => $enrollments->total(),
                'per_page' => $enrollments->perPage(),
                'current_page' => $enrollments->currentPage(),
                'last_page' => $enrollments->lastPage(),
            ]
        ]);
    }

    /**
     * GET /student/courses/{course_id}/lessons
     * List lessons + quiz status + progress tracking for this student
     */
    public function courseLessons($course_id)
    {
        $user_id = auth()->id();

        $enrolled = Enrollment::where('user_id', $user_id)
            ->where('course_id', $course_id)->exists();
        if (!$enrolled) return response()->json(['message' => 'Not enrolled in this course'], 403);

        $lessons = Lesson::where('course_id', $course_id)
            ->where('status', 'published')
            ->with([
                'quiz:id,lesson_id,title,duration_minutes',
                'quiz.attempts' => function ($q) use ($user_id) {
                    $q->where('student_id', $user_id)->latest();
                }
            ])
            ->orderBy('order')
            ->orderBy('created_at')
            ->get()
            ->map(function ($lesson) use ($user_id) {
                $quiz = $lesson->quiz;
                $attempt = $quiz?->attempts->first();
                
                // Get or create progress record
                $progress = LessonProgress::getOrCreate($user_id, $lesson->id);

                return [
                    'lesson_id' => $lesson->id,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'type' => $lesson->type,
                    'grade' => $lesson->grade,
                    'subject' => $lesson->subject,
                    'has_quiz' => (bool) $quiz,
                    'quiz_id' => $quiz->id ?? null,
                    'quiz_title' => $quiz->title ?? null,
                    'duration_minutes' => $quiz->duration_minutes ?? null,
                    'attempt_id' => $attempt->id ?? null,
                    'status' => $attempt->status ?? 'not_started',
                    'score' => $attempt->score ?? null,
                    'submitted_at' => $attempt->submitted_at ?? null,
                    // Progress tracking fields
                    'progress_status' => $progress->status,
                    'is_ongoing' => $progress->status === 'ongoing',
                    'lesson_read' => $progress->lesson_read,
                    'quiz_completed' => $progress->quiz_completed,
                ];
            });

        return response()->json([
            'course_id' => $course_id,
            'lessons' => $lessons
        ]);
    }
    /**
     * POST /student/quizzes/{quiz_id}/start
     * Create new quiz attempt or resume existing in_progress one
     */
    public function startQuiz($quiz_id)
    {
        $user_id = auth()->id();
        $quiz = Quiz::with('lesson.course')->find($quiz_id);
        if (!$quiz) return response()->json(['message' => 'Quiz not found'], 404);

        $enrolled = Enrollment::where('user_id', $user_id)
            ->where('course_id', $quiz->lesson->course_id)->exists();
        if (!$enrolled) return response()->json(['message' => 'Not enrolled'], 403);

        $existing = QuizAttempt::where('quiz_id', $quiz_id)
            ->where('student_id', $user_id)
            ->where('status', 'in_progress')->first();
        if ($existing) {
            return response()->json([
                'attempt_id' => $existing->id,
                'message' => 'Resume quiz',
                'status' => 'in_progress'
            ]);
        }

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz_id,
            'student_id' => $user_id,
            'status' => 'in_progress',
            'score' => 0
        ]);

        return response()->json([
            'attempt_id' => $attempt->id,
            'message' => 'Quiz started',
            'status' => 'in_progress'
        ], 201);
    }

    /**
     * POST /student/quiz-attempts/{attempt_id}/submit
     * Submit all answers. MCQs auto-graded, essays = pending
     */
    public function submitQuiz(Request $request, $attempt_id)
    {
        $user_id = auth()->id();
        $attempt = QuizAttempt::with(['quiz.questions'])->find($attempt_id);
        if (!$attempt || $attempt->student_id != $user_id)
            return response()->json(['message' => 'Unauthorized'], 403);
        if ($attempt->status !== 'in_progress')
            return response()->json(['message' => 'Quiz already submitted'], 409);

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array|min:1',
            'answers.*.question_id' => 'required|exists:questions,id|distinct',
            'answers.*.answer' => 'required|string'
        ]);
        if ($validator->fails()) return response()->json($validator->errors(), 422);

        DB::beginTransaction();
        try {
            $totalScore = 0;
            $questions = $attempt->quiz->questions->keyBy('id');

            $hasEssay = false;

            foreach ($request->answers as $ans) {
                $question = $questions->get($ans['question_id']);
                if (!$question) continue;

                $points = 0;
                $isCorrect = false;

                if (in_array($question->type, ['mcq', 'boolean'])) {
                    $isCorrect = trim($question->correct_answer) === trim($ans['answer']);
                    $points = $isCorrect ? $question->points : 0;
                } else {
                    $hasEssay = true;
                }

                $totalScore += $points;

                QuizAnswer::create([
                    'attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'student_answer' => $ans['answer'],
                    'is_correct' => $isCorrect,
                    'marks_awarded' => $points,
                    'feedback' => null,
                    'graded_by' => null,
                    'graded_at' => null
                ]);
            }

            $attempt->update([
                'score' => $totalScore,
                'status' => $hasEssay ? 'pending_grading' : 'graded',
                'submitted_at' => now()
            ]);

            DB::commit();
            return response()->json([
                'message' => 'Quiz submitted successfully',
                'attempt_id' => $attempt->id,
                'score' => $totalScore,
                'status' => 'submitted'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Submit failed', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /student/quiz-attempts/{attempt_id}
     * View attempt + answers + tutor feedback
     */
    public function viewAttempt($attempt_id)
    {
        $user_id = auth()->id();

        $attempt = QuizAttempt::with([
            'quiz:id,title',
            'answers.question:id,question_text,type,points',
            'answers:id,attempt_id,question_id,student_answer,marks_awarded,feedback,graded_at'
        ])
            ->where('id', $attempt_id)
            ->where('student_id', $user_id)
            ->first();

        if (!$attempt) return response()->json(['message' => 'Attempt not found'], 404);

        return response()->json([
            'attempt_id' => $attempt->id,
            'quiz_title' => $attempt->quiz->title,
            'status' => $attempt->status,
            'score' => $attempt->score,
            'submitted_at' => $attempt->submitted_at,
            'answers' => $attempt->answers->map(function ($a) {
                return [
                    'question' => $a->question->question_text,
                    'type' => $a->question->type,
                    'max_points' => $a->question->points,
                    'your_answer' => $a->student_answer,
                    'points_earned' => $a->marks_awarded,
                    'feedback' => $a->feedback,
                    'graded_at' => $a->graded_at
                ];
            })
        ]);
    }

    /**
     * POST /student/lessons/{lesson_id}/start
     * Mark lesson as started (change status from not_started to ongoing)
     */
    public function startLesson($lesson_id)
    {
        $user_id = auth()->id();

        // Verify lesson exists
        $lesson = Lesson::findOrFail($lesson_id);

        // Verify student is enrolled in the course
        $enrolled = Enrollment::where('user_id', $user_id)
            ->where('course_id', $lesson->course_id)->exists();
        if (!$enrolled) {
            return response()->json(['message' => 'Not enrolled in this course'], 403);
        }

        // Get or create progress record and mark as started
        $progress = LessonProgress::getOrCreate($user_id, $lesson_id);
        $progress->markStarted();

        return response()->json([
            'message' => 'Lesson started',
            'lesson_id' => $lesson_id,
            'status' => $progress->status,
            'is_ongoing' => $progress->status === 'ongoing'
        ], 200);
    }

    /**
     * POST /student/lessons/{lesson_id}/mark-read
     * Mark lesson content as read
     */
    public function markLessonRead($lesson_id)
    {
        $user_id = auth()->id();

        // Verify lesson exists
        $lesson = Lesson::findOrFail($lesson_id);

        // Verify student is enrolled in the course
        $enrolled = Enrollment::where('user_id', $user_id)
            ->where('course_id', $lesson->course_id)->exists();
        if (!$enrolled) {
            return response()->json(['message' => 'Not enrolled in this course'], 403);
        }

        // Get or create progress record
        $progress = LessonProgress::getOrCreate($user_id, $lesson_id);
        $progress->markLessonRead();

        return response()->json([
            'message' => 'Lesson marked as read',
            'lesson_id' => $lesson_id,
            'lesson_read' => $progress->lesson_read,
            'status' => $progress->status,
            'is_fully_completed' => $progress->isFullyCompleted()
        ], 200);
    }

    /**
     * POST /student/lessons/{lesson_id}/complete-quiz
     * Mark quiz as completed with score
     */
    public function completeQuiz(Request $request, $lesson_id)
    {
        $user_id = auth()->id();

        // Validate input
        $validator = Validator::make($request->all(), [
            'score' => 'required|numeric|min:0|max:100'
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Verify lesson exists
        $lesson = Lesson::findOrFail($lesson_id);

        // Verify student is enrolled in the course
        $enrolled = Enrollment::where('user_id', $user_id)
            ->where('course_id', $lesson->course_id)->exists();
        if (!$enrolled) {
            return response()->json(['message' => 'Not enrolled in this course'], 403);
        }

        // Get or create progress record
        $progress = LessonProgress::getOrCreate($user_id, $lesson_id);
        $progress->markQuizCompleted($request->input('score'));

        return response()->json([
            'message' => 'Quiz completed',
            'lesson_id' => $lesson_id,
            'quiz_completed' => $progress->quiz_completed,
            'quiz_score' => $progress->quiz_score,
            'attempts' => $progress->attempts,
            'status' => $progress->status,
            'is_fully_completed' => $progress->isFullyCompleted()
        ], 200);
    }

    /**
     * GET /student/dashboard-stats
     * Comprehensive student dashboard with enrolled courses, quiz scores, lessons, and discussions
     */
    public function dashboardStats()
    {
        $userId = auth()->id();

        // Get enrolled courses with progress
        $enrolledCourses = Enrollment::with(['course:id,title,type,price', 'course.tutor:id,name'])
            ->where('user_id', $userId)
            ->latest()
            ->get()
            ->map(function ($enrollment) use ($userId) {
                $course = $enrollment->course;
                $lessons = Lesson::where('course_id', $course->id)->count();
                $completedLessons = QuizAttempt::join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
                    ->join('lessons', 'lessons.id', '=', 'quizzes.lesson_id')
                    ->where('lessons.course_id', $course->id)
                    ->where('quiz_attempts.student_id', $userId)
                    ->where('quiz_attempts.status', 'graded')
                    ->distinct('lessons.id')
                    ->count('lessons.id');

                $progress = $lessons > 0 ? round(($completedLessons / $lessons) * 100) : 0;

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'tutor' => $course->tutor?->name ?? 'Unknown',
                    'type' => $course->type,
                    'price' => $course->type === 'paid' ? '$' . number_format($course->price, 2) : 'Free',
                    'progress' => $progress,
                    'enrolled_at' => $enrollment->enrolled_at->format('M d, Y'),
                ];
            });

        // Get recent quiz scores (last 5)
        $recentQuizzes = QuizAttempt::with(['quiz:id,title,lesson_id', 'quiz.lesson:id,course_id,title', 'quiz.lesson.course:id,title'])
            ->where('student_id', $userId)
            ->where('status', 'graded')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($attempt) {
                return [
                    'quiz_id' => $attempt->quiz_id,
                    'quiz_title' => $attempt->quiz->title,
                    'subject' => $attempt->quiz->lesson->course->title ?? 'General',
                    'score' => $attempt->score . '/100',
                    'date' => $attempt->submitted_at->format('M d'),
                    'status' => $attempt->status,
                ];
            });

        // Get recent lessons
        $courseIds = Enrollment::where('user_id', $userId)->pluck('course_id');
        $recentLessons = Lesson::whereIn('course_id', $courseIds)
            ->with(['course:id,title'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($lesson) use ($userId) {
                // Check if student has completed this lesson
                $hasAttempt = QuizAttempt::where('student_id', $userId)
                    ->whereHas('quiz', function ($q) use ($lesson) {
                        $q->where('lesson_id', $lesson->id);
                    })
                    ->exists();

                $status = $hasAttempt ? 'COMPLETED' : 'IN PROGRESS';

                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'course' => $lesson->course->title,
                    'type' => $lesson->type,
                    'date' => $lesson->created_at->format('M d, Y'),
                    'status' => $status,
                ];
            });

        // Get active discussions
        $activeDiscussions = \App\Models\CourseForumThread::with(['user:id,name', 'course:id,title', 'lesson:id,title'])
            ->whereIn('course_id', $courseIds)
            ->withCount('replies')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($thread) {
                return [
                    'thread_id' => $thread->id,
                    'question' => $thread->question,
                    'asked_by' => $thread->user->name,
                    'subject' => $thread->course->title,
                    'replies_count' => $thread->replies_count,
                    'time' => $thread->created_at->diffForHumans(),
                    'preview' => substr($thread->question, 0, 80) . (strlen($thread->question) > 80 ? '...' : ''),
                ];
            });

        return response()->json([
            'enrolled_courses' => $enrolledCourses,
            'recent_quiz_scores' => $recentQuizzes,
            'recent_lessons' => $recentLessons,
            'active_discussions' => $activeDiscussions,
            'summary' => [
                'total_enrolled_courses' => $enrolledCourses->count(),
                'average_progress' => round($enrolledCourses->avg('progress')),
                'total_quiz_attempts' => QuizAttempt::where('student_id', $userId)->count(),
                'active_discussions_count' => $activeDiscussions->count(),
            ]
        ]);
    }

    /**
     * GET /student/available-courses
     * Get all available courses for students to browse and enroll
     */
    public function availableCourses()
    {
        $userId = auth()->id();

        // Get courses student is already enrolled in
        $enrolledCourseIds = Enrollment::where('user_id', $userId)
            ->pluck('course_id');

        // Get all courses excluding enrolled ones
        $courses = Course::with(['tutor:id,name,email', 'lessons' => function ($query) {
            $query->count();
        }])
            ->whereNotIn('id', $enrolledCourseIds)
            ->latest()
            ->get()
            ->map(function ($course) {
                $totalLessons = Lesson::where('course_id', $course->id)->count();
                $enrollmentCount = Enrollment::where('course_id', $course->id)->count();

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'description' => $course->description,
                    'tutor' => $course->tutor?->name ?? 'Unknown',
                    'tutor_email' => $course->tutor?->email ?? null,
                    'type' => $course->type,
                    'price' => $course->type === 'paid' ? '$' . number_format($course->price, 2) : 'Free',
                    'total_lessons' => $totalLessons,
                    'enrolled_students' => $enrollmentCount,
                    'created_at' => $course->created_at->format('M d, Y'),
                ];
            });

        return response()->json([
            'message' => 'Available courses retrieved successfully',
            'data' => $courses,
            'total' => $courses->count(),
        ]);
    }

    /**
     * GET /api/student/profile
     * Get student profile with all information
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'message' => 'Profile retrieved successfully',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'education_level' => $user->education_level,
                'title' => $user->title,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone_number' => $user->phone_number,
                'location' => $user->location,
                'biography' => $user->biography,
                'school_name' => $user->school_name,
                'core_subjects' => $user->core_subjects,
                'avatar_url' => $user->avatar_url ?? null,
                'is_approved' => $user->is_approved,
                'approved_at' => $user->approved_at,
                'created_at' => $user->created_at,
            ]
        ], 200);
    }

    /**
     * PUT /api/student/profile
     * Update student profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:50',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|unique:users,phone_number,' . $user->id,
            'phone_number' => 'nullable|string|unique:users,phone_number,' . $user->id,
            'location' => 'nullable|string|max:255',
            'biography' => 'nullable|string|max:1000',
            'school_name' => 'nullable|string|max:255',
            'core_subjects' => 'nullable|array',
            'core_subjects.*' => 'string|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->only([
                'title',
                'first_name',
                'last_name',
                'location',
                'biography',
                'school_name',
                'core_subjects'
            ]);

            // Handle phone field mapping - accept both 'phone' and 'phone_number'
            if ($request->has('phone')) {
                $data['phone_number'] = $request->phone;
            } elseif ($request->has('phone_number')) {
                $data['phone_number'] = $request->phone_number;
            }

            // Remove null values to only update provided fields
            $data = array_filter($data, function ($value) {
                return $value !== null;
            });

            $user->update($data);

            return response()->json([
                'message' => 'Profile updated successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'title' => $user->title,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'phone' => $user->phone_number,
                    'location' => $user->location,
                    'biography' => $user->biography,
                    'school_name' => $user->school_name,
                    'core_subjects' => $user->core_subjects,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload student profile picture/avatar
     * POST /api/student/upload-avatar
     */
    public function uploadAvatar(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('avatar');
            $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();

            // Store file in avatars directory
            $filePath = $file->storeAs('avatars', $filename, 'public');

            // Delete old avatar if it exists
            if ($user->avatar_url) {
                $oldPath = str_replace(config('app.url') . '/storage/', '', $user->avatar_url);
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            // Update user avatar URL
            $avatarUrl = Storage::disk('public')->url($filePath);
            $user->update(['avatar_url' => $avatarUrl]);

            return response()->json([
                'message' => 'Avatar uploaded successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to upload avatar',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/student/settings
     * Update student account settings (email, password notifications, etc)
     */
    public function updateSettings(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'receive_notifications' => 'nullable|boolean',
            'receive_emails' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            if ($request->has('email') && $request->email !== $user->email) {
                $user->email = $request->email;
                $user->email_verified_at = null; // Reset email verification
            }

            // Note: Add more settings as needed in your application
            // For now, we're handling email changes
            $user->save();

            return response()->json([
                'message' => 'Settings updated successfully',
                'data' => [
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/student/goals
     * List all student goals with filtering options
     */
    public function getGoals(Request $request)
    {
        $user = $request->user();
        $status = $request->query('status'); // Filter by status: active, completed, abandoned

        $query = StudentGoal::where('user_id', $user->id);

        if ($status && in_array($status, ['active', 'completed', 'abandoned'])) {
            $query->where('status', $status);
        }

        $goals = $query->orderBy('target_date', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();

        $summary = [
            'total_goals' => StudentGoal::where('user_id', $user->id)->count(),
            'active_goals' => StudentGoal::where('user_id', $user->id)->where('status', 'active')->count(),
            'completed_goals' => StudentGoal::where('user_id', $user->id)->where('status', 'completed')->count(),
            'abandoned_goals' => StudentGoal::where('user_id', $user->id)->where('status', 'abandoned')->count(),
        ];

        return response()->json([
            'message' => 'Goals retrieved successfully',
            'data' => $goals,
            'summary' => $summary
        ], 200);
    }

    /**
     * POST /api/student/goals
     * Create a new goal
     */
    public function createGoal(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'target_date' => 'nullable|date_format:Y-m-d H:i:s|after:now',
            'category' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $goal = StudentGoal::create([
                'user_id' => $user->id,
                'title' => $request->title,
                'description' => $request->description,
                'target_date' => $request->target_date,
                'category' => $request->category,
                'status' => 'active',
                'progress' => 0,
            ]);

            return response()->json([
                'message' => 'Goal created successfully',
                'data' => $goal
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create goal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/student/goals/{id}
     * Update a goal
     */
    public function updateGoal(Request $request, $id)
    {
        $user = $request->user();
        $goal = StudentGoal::find($id);

        if (!$goal) {
            return response()->json([
                'message' => 'Goal not found'
            ], 404);
        }

        if ($goal->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|in:active,completed,abandoned',
            'progress' => 'nullable|integer|min:0|max:100',
            'target_date' => 'nullable|date_format:Y-m-d H:i:s',
            'category' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->only(['title', 'description', 'status', 'progress', 'target_date', 'category']);
            
            // If progress is updated
            if (isset($data['progress'])) {
                $data['progress'] = max(0, min(100, $data['progress']));
                
                // Auto-complete if progress reaches 100
                if ($data['progress'] >= 100) {
                    $data['status'] = 'completed';
                }
            }

            $goal->update($data);

            return response()->json([
                'message' => 'Goal updated successfully',
                'data' => $goal
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update goal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/student/goals/{id}
     * Delete a goal
     */
    public function deleteGoal(Request $request, $id)
    {
        $user = $request->user();
        $goal = StudentGoal::find($id);

        if (!$goal) {
            return response()->json([
                'message' => 'Goal not found'
            ], 404);
        }

        if ($goal->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        try {
            $goal->delete();

            return response()->json([
                'message' => 'Goal deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete goal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/student/growth
     * Get student XP, level, and gamification data
     */
    public function getGrowth(Request $request)
    {
        $user = $request->user();

        // Get or create growth record
        $growth = StudentGrowth::firstOrCreate(['user_id' => $user->id], [
            'xp' => 0,
            'level' => 1,
            'xp_to_next_level' => StudentGrowth::getXpForLevel(1),
        ]);

        return response()->json([
            'message' => 'Growth data retrieved successfully',
            'data' => [
                'user_id' => $user->id,
                'xp' => $growth->xp,
                'level' => $growth->level,
                'xp_to_next_level' => $growth->xp_to_next_level,
                'progress_to_next_level' => $growth->getProgressToNextLevel(),
                'streaks' => $growth->streaks,
                'total_quizzes_completed' => $growth->total_quizzes_completed,
                'total_lessons_completed' => $growth->total_lessons_completed,
                'average_score' => round($growth->average_score, 2),
                'last_activity_at' => $growth->last_activity_at,
                'rank' => $this->calculateRank($user->education_level),
            ]
        ], 200);
    }

    /**
     * Helper function to calculate student rank based on level
     */
    private function calculateRank($level): string
    {
        $ranks = [
            0 => 'Beginner',
            1 => 'Novice',
            5 => 'Intermediate',
            10 => 'Advanced',
            15 => 'Expert',
            20 => 'Master',
        ];

        $rank = 'Beginner';
        foreach ($ranks as $levelRequired => $rankName) {
            if ($level >= $levelRequired) {
                $rank = $rankName;
            }
        }

        return $rank;
    }
}
