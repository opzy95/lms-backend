<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TutorController extends Controller
{
    // POST /api/tutor/courses
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|in:free,paid',
            'price' => 'nullable|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Force price=0 for free courses
        $price = $request->type === 'free' ? 0 : $request->price;

        if ($request->type === 'paid' && $price <= 0) {
            return response()->json(['message' => 'Paid courses must have price > 0'], 422);
        }

        $course = Course::create([
            'tutor_id' => $request->user()->id, // auto-fill logged in tutor
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type,
            'price' => $price
        ]);

        return response()->json([
            'message' => 'Course created successfully',
            'course' => $course
        ], 201);
    }

    // GET /api/tutor/courses - list only this tutor's courses
    public function index(Request $request)
    {
        $courses = Course::where('tutor_id', $request->user()->id)
                         ->latest()
                         ->get();
        return response()->json($courses);
    }

    // PUT /api/tutor/courses/{id} - update course
   public function update(Request $request, $id)
{
    $course = Course::where('id', $id)
        ->where('tutor_id', $request->user()->id)
        ->first();

    if (!$course) {
        return response()->json(['message' => 'Course not found'], 404);
    }

    $validator = Validator::make($request->all(), [
        'title' => 'sometimes|string|max:255',
        'description' => 'sometimes|string',
        'type' => 'sometimes|in:free,paid',
        'price' => 'nullable|numeric|min:0',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $data = $request->only(['title', 'description', 'type', 'price']);
    if (($data['type'] ?? $course->type) === 'free') {
        $data['price'] = 0;
    }

    $course->update($data);

    return response()->json(['message' => 'Course updated', 'course' => $course]);
}

  public function dashboard()
{
    $tutorId = auth()->id();

    $courses = Course::where('tutor_id', $tutorId)
        ->withCount('lessons')
        ->get();

    $courseIds = $courses->pluck('id');

    // One query: enrollment counts per course
    $enrollCounts = Enrollment::whereIn('course_id', $courseIds)
        ->selectRaw('course_id, count(*) as total')
        ->groupBy('course_id')
        ->pluck('total', 'course_id');

    // One query: active (distinct) students per course
    $activeCounts = QuizAttempt::join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
        ->join('lessons', 'lessons.id', '=', 'quizzes.lesson_id')
        ->whereIn('lessons.course_id', $courseIds)
        ->selectRaw('lessons.course_id, count(distinct quiz_attempts.student_id) as total')
        ->groupBy('lessons.course_id')
        ->pluck('total', 'course_id');

    // One query: pending essays per course
    $pendingCounts = QuizAnswer::whereNull('graded_at')
        ->whereHas('question', fn($q) => $q->where('type', 'essay'))
        ->whereHas('attempt.quiz.lesson', fn($q) => $q->whereIn('course_id', $courseIds))
        ->with('attempt.quiz.lesson:id,course_id')
        ->get()
        ->groupBy('attempt.quiz.lesson.course_id')
        ->map->count();

    return response()->json($courses->map(function ($course) use ($enrollCounts, $activeCounts, $pendingCounts) {
        return [
            'course_id' => $course->id,
            'title' => $course->title,
            'lessons_count' => $course->lessons_count,
            'total_enrolled' => $enrollCounts[$course->id] ?? 0,
            'active_students' => $activeCounts[$course->id] ?? 0,
            'pending_essays' => $pendingCounts[$course->id] ?? 0,
        ];
    }));
}

     public function courseStudents($course_id)
    {
        $course = Course::where('id', $course_id)
            ->where('tutor_id', auth()->id())->first();
        if(!$course) return response()->json(['message' => 'Unauthorized'], 403);

        $students = Enrollment::with([
            'user:id,name,email',
            'user.quizAttempts' => function($q) use ($course_id) {
                $q->whereHas('quiz.lesson', fn($l) => $l->where('course_id', $course_id));
            }
        ])
        ->where('course_id', $course_id)
        ->get()
        ->map(function($enroll) {
            return [
                'user_id' => $enroll->user_id,
                'name' => $enroll->user->name,
                'email' => $enroll->user->email,
                'enrolled_at' => $enroll->enrolled_at,
                'quizzes_submitted' => $enroll->user->quizAttempts->count(),
                'last_activity' => $enroll->user->quizAttempts->max('submitted_at')
            ];
        });

        return response()->json([
            'course_id' => $course_id,
            'course_title' => $course->title,
            'total_enrolled' => $students->count(),
            'students' => $students
        ]);
    }

      public function lessonStudents($lesson_id)
    {
        $lesson = Lesson::where('id', $lesson_id)
    ->whereHas('course', fn($q) => $q->where('tutor_id', auth()->id()))
    ->first();
        if(!$lesson) return response()->json(['message' => 'Unauthorized'], 403);

        $quiz = Quiz::where('lesson_id', $lesson_id)->first();

        $enrolled = Enrollment::with('user:id,name,email')
            ->where('course_id', $lesson->course_id)->get();

        $submittedIds = $quiz ? 
            QuizAttempt::where('quiz_id', $quiz->id)->pluck('user_id') : 
            collect();

        $students = $enrolled->map(function($e) use ($submittedIds) {
            return [
                'user_id' => $e->user_id,
                'name' => $e->user->name,
                'email' => $e->user->email,
                'status' => $submittedIds->contains($e->user_id) ? 'submitted' : 'not_started'
            ];
        });

        return response()->json([
            'lesson_id' => $lesson_id,
            'lesson_title' => $lesson->title,
            'total_enrolled' => $students->count(),
            'submitted_count' => $submittedIds->count(),
            'students' => $students
        ]);
    }
    
}