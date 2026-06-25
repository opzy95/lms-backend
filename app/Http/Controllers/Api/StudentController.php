<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use App\Models\QuizAnswer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

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
     * GET /student/courses/{course_id}/lessons
     * List lessons + quiz status for this student
     */
    public function courseLessons($course_id)
    {
        $user_id = auth()->id();

        $enrolled = Enrollment::where('user_id', $user_id)
            ->where('course_id', $course_id)->exists();
        if (!$enrolled) return response()->json(['message' => 'Not enrolled in this course'], 403);

        $lessons = Lesson::where('course_id', $course_id)
            ->with([
                'quiz:id,lesson_id,title,duration_minutes',
                'quiz.attempts' => function ($q) use ($user_id) {
                    $q->where('student_id', $user_id)->latest();
                }
            ])
            ->orderBy('id')
            ->get()
            ->map(function ($lesson) {
                $quiz = $lesson->quiz;
                $attempt = $quiz?->attempts->first();

                return [
                    'lesson_id' => $lesson->id,
                    'title' => $lesson->title,
                    'has_quiz' => (bool) $quiz,
                    'quiz_id' => $quiz->id ?? null,
                    'quiz_title' => $quiz->title ?? null,
                    'duration_minutes' => $quiz->duration_minutes ?? null,
                    'attempt_id' => $attempt->id ?? null,
                    'status' => $attempt->status ?? 'not_started',
                    'score' => $attempt->score ?? null,
                    'submitted_at' => $attempt->submitted_at ?? null
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
}
