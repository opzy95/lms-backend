<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class LessonController extends Controller
{
    // GET /api/tutor/lessons
    public function allLessons()
    {
        $tutorId = auth()->id();

        $lessons = Lesson::whereHas('course', function ($query) use ($tutorId) {
            $query->where('tutor_id', $tutorId);
        })
        ->with('course')
        ->orderBy('created_at', 'desc')
        ->get();

        // Get total enrolled students across all courses
        $totalEnrolledStudents = Enrollment::whereHas('course', function ($query) use ($tutorId) {
            $query->where('tutor_id', $tutorId);
        })->distinct('user_id')->count('user_id');

        // Format lessons with grade and enrollment data
        $formattedLessons = $lessons->map(function ($lesson) {
            // Get quiz attempts for this lesson to calculate average grade
            $quizAttempts = QuizAttempt::join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
                ->where('quizzes.lesson_id', $lesson->id)
                ->where('quiz_attempts.status', 'graded')
                ->pluck('quiz_attempts.score');

            $averageGrade = $quizAttempts->isNotEmpty() 
                ? round($quizAttempts->avg(), 2)
                : 0;

            return [
                'id' => $lesson->id,
                'course_id' => $lesson->course_id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'type' => $lesson->type,
                'video_url' => $lesson->video_url,
                'content' => $lesson->content,
                'file_path' => $lesson->file_path,
                'file_name' => $lesson->file_name,
                'order' => $lesson->order,
                'grade' => $lesson->grade,
                'subject' => $lesson->subject,
                'status' => $lesson->status,
                'average_quiz_grade' => $averageGrade . '%',
                'has_quiz' => $lesson->quiz !== null,
                'created_at' => $lesson->created_at,
                'updated_at' => $lesson->updated_at,
            ];
        });

        return response()->json([
            'message' => 'All lessons retrieved successfully',
            'total_enrolled_students' => $totalEnrolledStudents,
            'lessons' => $formattedLessons,
            'total' => $lessons->count()
        ]);
    }

    // GET /api/tutor/courses/{course}/lessons
    public function index(Course $course)
    {
        // Only course owner can view lessons
        if ($course->tutor_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $lessons = $course->lessons()
            ->orderBy('order', 'asc')
            ->get();

        return response()->json([
            'message' => 'Lessons retrieved successfully',
            'lessons' => $lessons,
            'total' => $lessons->count()
        ]);
    }

    // GET /api/tutor/lessons/{lesson}
    public function show(Lesson $lesson)
    {
        // Only tutor can view their own lesson details
        if ($lesson->tutor_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Load related data
        $lesson->load('course', 'quiz');

        // Get quiz attempts for this lesson to calculate stats
        $quizAttempts = QuizAttempt::join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->where('quizzes.lesson_id', $lesson->id)
            ->where('quiz_attempts.status', 'graded')
            ->pluck('quiz_attempts.score');

        $averageGrade = $quizAttempts->isNotEmpty() 
            ? round($quizAttempts->avg(), 2)
            : 0;

        $studentsAttempted = QuizAttempt::join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->where('quizzes.lesson_id', $lesson->id)
            ->where('quiz_attempts.status', 'graded')
            ->distinct('quiz_attempts.student_id')
            ->count('quiz_attempts.student_id');

        // Get total enrolled students in the course
        $totalEnrolled = Enrollment::where('course_id', $lesson->course_id)->count();

        return response()->json([
            'message' => 'Lesson details retrieved successfully',
            'lesson' => [
                'id' => $lesson->id,
                'course_id' => $lesson->course_id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'type' => $lesson->type,
                'video_url' => $lesson->video_url,
                'content' => $lesson->content,
                'file_path' => $lesson->file_path,
                'file_name' => $lesson->file_name,
                'order' => $lesson->order,
                'grade' => $lesson->grade,
                'subject' => $lesson->subject,
                'status' => $lesson->status,
                'has_quiz' => $lesson->quiz !== null,
                'average_grade' => $averageGrade . '%',
                'students_attempted' => $studentsAttempted,
                'total_enrolled' => $totalEnrolled,
                'created_at' => $lesson->created_at,
                'updated_at' => $lesson->updated_at,
            ]
        ]);
    }

    // POST /api/tutor/courses/{course}/lessons
   public function store(Request $request, Course $course)
{


    // return response()->json([
    //     'auth_id' => auth()->id(),
    //     'course_id' => $course->id,
    //     'course_tutor_id' => $course->tutor_id,
    // ]);

    if ($course->tutor_id !== auth()->id()) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    // Only course owner can add lessons
    if ($course->tutor_id !== auth()->id()) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $validator = Validator::make($request->all(), [
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'type' => 'required|in:video,text,file',

        'video_url' => 'required_if:type,video|nullable|url',
        'content' => 'required_if:type,text|nullable|string',

        'file' => 'required_if:type,file|nullable|file|mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/zip,application/x-zip-compressed|max:51200',

        'order' => 'nullable|integer|min:0',
        
    ]);

    if ($validator->fails()) {

        if (
            $request->hasFile('file') &&
            $request->file('file')->getSize() > (50 * 1024 * 1024)
        ) {
            return response()->json([
                'message' => 'File too large. Max upload size is 50MB'
            ], 413);
        }

        return response()->json([
            'errors' => $validator->errors()
        ], 422);
    }

    $filePath = null;
    $fileName = null;

    if ($request->type === 'file' && $request->hasFile('file')) {

        $file = $request->file('file');

        $fileName = $file->getClientOriginalName();

        $filePath = $file->store(
            'course_files',
            'public'
        );
    }

    // Auto-generate order if not supplied
    $order = $request->order ??
        (($course->lessons()->max('order') ?? -1) + 1);

    $lesson = $course->lessons()->create([
        'title' => $request->title,
        'description' => $request->description,
        'type' => $request->type,

        'video_url' => $request->type === 'video'
            ? $request->video_url
            : null,

        'content' => $request->type === 'text'
            ? $request->content
            : null,

        'file_path' => $filePath,
        'file_name' => $fileName,

        'order' => $order,
         'status' => $request->status ?? 'PUBLISHED',
        'tutor_id' => auth()->id()
    ]);

    return response()->json([
        'message' => 'Lesson created successfully',
        'lesson' => $lesson
    ], 201);
}
    // PUT /api/tutor/lessons/{lesson}
    public function update(Request $request, Lesson $lesson)
    {
        if ($lesson->course->tutor_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->only(['title', 'description', 'order', 'grade']);

        // Handle file replacement
        if ($request->type === 'file' && $request->hasFile('file')) {
            // Delete old file
            if ($lesson->file_path) {
                Storage::disk('public')->delete($lesson->file_path);
            }
            $file = $request->file('file');
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_path'] = $file->store('course_files', 'public');
        }

        $lesson->update($data);

        return response()->json([
            'message' => 'Lesson updated',
            'lesson' => $lesson
        ]);
    }

    // DELETE /api/tutor/lessons/{lesson}
   public function destroy(Lesson $lesson)
{
    if ($lesson->course->tutor_id !== auth()->id()) {
        return response()->json([
            'message' => 'Unauthorized'
        ], 403);
    }

    DB::beginTransaction();

    try {

        // Delete lesson file
        if (
            $lesson->file_path &&
            Storage::disk('public')->exists($lesson->file_path)
        ) {
            Storage::disk('public')->delete($lesson->file_path);
        }

        $lesson->delete();

        DB::commit();

        return response()->json([
            'message' => 'Lesson deleted successfully'
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'message' => 'Failed to delete lesson',
            'error' => $e->getMessage()
        ], 500);
    }
}

    // GET /api/student/lessons/{lesson}/download
    public function download(Lesson $lesson)
{
    $userId = auth()->id();

    $isOwner = $lesson->course->tutor_id === $userId;

    $isEnrolled = auth()->user()
        ->enrollments()
        ->where('course_id', $lesson->course_id)
        ->exists();

    if (!$isOwner && !$isEnrolled) {
        return response()->json([
            'message' => 'Not enrolled in this course'
        ], 403);
    }

    if (!$lesson->file_path) {
        return response()->json([
            'message' => 'No file available for this lesson'
        ], 404);
    }

    if (!Storage::disk('public')->exists($lesson->file_path)) {
        return response()->json([
            'message' => 'File not found'
        ], 404);
    }

    if ($lesson->type !== 'file') {
    return response()->json([
        'message' => 'This lesson does not contain a downloadable file'
    ], 400);
}

    $fileName = $lesson->file_name
        ?? basename($lesson->file_path);

    return Storage::disk('public')
        ->download($lesson->file_path, $fileName);
}

    /**
     * GET /api/tutor/lessons/stats
     * Get lesson stats and course modules for tutor dashboard
     */
    public function lessonStats()
    {
        $tutorId = auth()->id();

        // Get all lessons for this tutor
        $lessons = Lesson::whereHas('course', function ($query) use ($tutorId) {
            $query->where('tutor_id', $tutorId);
        })
        ->with('course', 'quiz')
        ->get();

        // Calculate stats
        $totalLessons = $lessons->count();
        $lessonsWithQuiz = $lessons->filter(function ($lesson) {
            return $lesson->quiz !== null;
        })->count();

        // Get all courses with their lessons
        $courses = Course::where('tutor_id', $tutorId)
            ->with(['lessons' => function ($query) {
                $query->orderBy('order', 'asc');
            }])
            ->latest()
            ->get();

        // Format courses with lesson data
        $courseModules = $courses->map(function ($course) {
            // Count enrolled students for this course
            $enrolledStudents = Enrollment::where('course_id', $course->id)->count();

            $lessons = $course->lessons->map(function ($lesson) use ($enrolledStudents) {
                // Get quiz attempts for this lesson to calculate average score
                $quizAttempts = QuizAttempt::join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
                    ->where('quizzes.lesson_id', $lesson->id)
                    ->where('quiz_attempts.status', 'graded')
                    ->pluck('quiz_attempts.score');

                $averageScore = $quizAttempts->isNotEmpty() 
                    ? round($quizAttempts->avg(), 2)
                    : 0;

                $studentsAttempted = QuizAttempt::join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
                    ->where('quizzes.lesson_id', $lesson->id)
                    ->where('quiz_attempts.status', 'graded')
                    ->distinct('quiz_attempts.student_id')
                    ->count('quiz_attempts.student_id');

                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'type' => $lesson->type,
                    'order' => $lesson->order,
                    'has_quiz' => $lesson->quiz !== null,
                    'quiz_count' => $lesson->quiz ? 1 : 0,
                    'status' => $lesson->status ?? 'active',
                    'created_at' => $lesson->created_at->format('M d, Y'),
                    'average_grade' => $averageScore . '%',
                    'students_attempted' => $studentsAttempted,
                    'total_enrolled' => $enrolledStudents,
                ];
            });

            return [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'type' => $course->type,
                'price' => $course->type === 'paid' ? '$' . number_format($course->price, 2) : 'Free',
                'lesson_count' => $lessons->count(),
                'enrolled_students' => $enrolledStudents,
                'lessons' => $lessons,
            ];
        });

        return response()->json([
            'stats' => [
                'total_lessons_taught' => $totalLessons,
                'avg_attendance' => 94, // Placeholder
                'active_students' => 0, // Can be calculated from enrollments
                'content_mastery' => 88, // Placeholder
                'lessons_with_quiz' => $lessonsWithQuiz,
            ],
            'course_modules' => $courseModules,
            'summary' => [
                'total_courses' => $courses->count(),
                'total_lessons' => $totalLessons,
                'lessons_with_assessments' => $lessonsWithQuiz,
            ]
        ]);
    }
}
