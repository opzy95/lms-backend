<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\Question;
use App\Models\QuizAttempt;
use App\Models\QuizAnswer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class QuizController extends Controller
{
    // TUTOR: Create quiz with MCQ, boolean, essay
    public function store(Request $request, $lesson_id)
{
    $request->validate([
        'title' => 'required|string|max:255',
        'duration_minutes' => 'required|integer|min:1',

        'questions' => 'required|array|min:1',
        'questions.*.question_text' => 'required|string',
        'questions.*.type' => 'required|in:mcq,boolean,essay',
        'questions.*.options' => 'required_if:questions.*.type,mcq|array|min:2',
        'questions.*.correct_answer' => 'required_if:questions.*.type,mcq,boolean|string',
        'questions.*.points' => 'required|integer|min:1'
    ]);

    $lesson = Lesson::where('id', $lesson_id)
        ->whereHas('course', function ($q) {
            $q->where('tutor_id', auth()->id());
        })
        ->first();

    if (!$lesson) {
        return response()->json([
            'message' => 'Unauthorized'
        ], 403);
    }

    DB::beginTransaction();

    try {

        // Check if quiz already exists - if so, delete it first (one quiz per lesson only)
        $existingQuiz = Quiz::where('lesson_id', $lesson_id)->first();
        if ($existingQuiz) {
            $existingQuiz->questions()->delete();
            $existingQuiz->delete();
        }

        $quiz = Quiz::create([
            'lesson_id' => $lesson_id,
            'title' => $request->title,
            'duration_minutes' => $request->duration_minutes,
            'created_by' => auth()->id()
        ]);

        foreach ($request->questions as $q) {

            // Handle boolean type - convert various formats to Yes/No
            if ($q['type'] === 'boolean') {
                $correctAnswer = $q['correct_answer'];
                
                // Convert boolean/numeric values to Yes/No
                if (is_bool($correctAnswer)) {
                    $correctAnswer = $correctAnswer ? 'Yes' : 'No';
                } elseif (is_numeric($correctAnswer)) {
                    $correctAnswer = $correctAnswer == 1 ? 'Yes' : 'No';
                } elseif (is_string($correctAnswer)) {
                    // Accept "Yes"/"No", "yes"/"no", "true"/"false", "1"/"0"
                    $lower = strtolower($correctAnswer);
                    if (in_array($lower, ['yes', 'true', '1'])) {
                        $correctAnswer = 'Yes';
                    } elseif (in_array($lower, ['no', 'false', '0'])) {
                        $correctAnswer = 'No';
                    }
                }
                
                if (!in_array($correctAnswer, ['Yes', 'No'])) {
                    throw new \Exception(
                        'Boolean correct_answer must be Yes or No (or true/false, 1/0, yes/no)'
                    );
                }
                
                $q['correct_answer'] = $correctAnswer;
            }

            $options = null;

            if ($q['type'] === 'boolean') {
                $options = json_encode(['Yes', 'No']);
            } elseif (isset($q['options'])) {
                $options = json_encode($q['options']);
            }

            Question::create([
                'quiz_id' => $quiz->id,
                'question_text' => $q['question_text'],
                'type' => $q['type'],
                'options' => $options,
                'correct_answer' => in_array(
                    $q['type'],
                    ['mcq', 'boolean']
                )
                    ? $q['correct_answer']
                    : null,
                'points' => $q['points']
            ]);
        }

        DB::commit();

        return response()->json([
            'message' => 'Quiz created successfully',
            'quiz_id' => $quiz->id
        ], 201);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'message' => $e->getMessage()
        ], 500);
    }
}

    // STUDENT: Start quiz - get questions without answers
   public function start($quiz_id)
{
    $quiz = Quiz::with([
        'questions',
        'lesson.course'
    ])->findOrFail($quiz_id);

    $user = auth()->user();

    // Verify enrollment or ownership
    $isOwner = $quiz->lesson->course->tutor_id === $user->id;

    $isEnrolled = $user->enrollments()
        ->where('course_id', $quiz->lesson->course_id)
        ->exists();

    if (!$isOwner && !$isEnrolled) {
        return response()->json([
            'message' => 'You are not enrolled in this course'
        ], 403);
    }

    // Prevent retake after submission
    $existing = QuizAttempt::where('quiz_id', $quiz_id)
        ->where('student_id', $user->id)
        ->first();

    if ($existing && $existing->submitted_at) {
        return response()->json([
            'message' => 'Quiz already submitted'
        ], 400);
    }

    $attempt = QuizAttempt::firstOrCreate(
        [
            'quiz_id' => $quiz_id,
            'student_id' => $user->id
        ],
        [
            'started_at' => now()
        ]
    );

    $questions = $quiz->questions->map(function ($q) {
        return [
            'id' => $q->id,
            'question_text' => $q->question_text,
            'type' => $q->type,
            'options' => $q->options
                ? json_decode($q->options, true)
                : null,
            'points' => $q->points
        ];
    });

    return response()->json([
        'attempt_id' => $attempt->id,
        'quiz' => [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'duration' => $quiz->duration_minutes
        ],
        'questions' => $questions
    ]);
}

    // STUDENT: Submit answers
    public function submit(Request $request, $quiz_id)
{
    $request->validate([
        'answers' => 'required|array|min:1',
        'answers.*.question_id' => 'required|exists:questions,id',
        'answers.*.answer' => 'required|string'
    ]);

    $quiz = Quiz::findOrFail($quiz_id);

    $attempt = QuizAttempt::where('quiz_id', $quiz_id)
        ->where('student_id', auth()->id())
        ->firstOrFail();

    if ($attempt->submitted_at) {
        return response()->json([
            'message' => 'Already submitted'
        ], 400);
    }

    $autoScore = 0;
    $pendingEssay = false;

    DB::beginTransaction();

    try {

        foreach ($request->answers as $ans) {

            // Ensure question belongs to this quiz
            $q = Question::where('id', $ans['question_id'])
                ->where('quiz_id', $quiz_id)
                ->first();

            if (!$q) {
                throw new \Exception('Invalid question submitted');
            }

            // Prevent duplicate answers
            $alreadyAnswered = QuizAnswer::where('attempt_id', $attempt->id)
                ->where('question_id', $q->id)
                ->exists();

            if ($alreadyAnswered) {
                throw new \Exception('Duplicate question submission');
            }

            $marks = 0;
            $isCorrect = false;

            if (in_array($q->type, ['mcq', 'boolean'])) {

                $isCorrect = $q->correct_answer === $ans['answer'];

                $marks = $isCorrect
                    ? $q->points
                    : 0;

                $autoScore += $marks;

            } else {

                // Essay questions require tutor grading
                $pendingEssay = true;
            }

            QuizAnswer::create([
                'attempt_id' => $attempt->id,
                'question_id' => $q->id,
                'student_answer' => $ans['answer'],
                'is_correct' => $isCorrect,
                'marks_awarded' => $marks
            ]);
        }

        $attempt->update([
            'score' => $autoScore,
            'submitted_at' => now(),
            'status' => $pendingEssay
                ? 'pending_grading'
                : 'graded'
        ]);

        DB::commit();

        return response()->json([
        'message' => $pendingEssay
                ? 'Submitted. Essays pending tutor grading'
                : 'Submitted successfully',
            'score' => $autoScore,
            'status' => $pendingEssay
                ? 'pending_grading'
                : 'graded'
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'message' => 'Submit failed',
            'error' => $e->getMessage()
        ], 500);
    }
}
    
    // TUTOR: Grade essay answer
    public function gradeAnswer(Request $request, $answer_id)
{
    $request->validate([
        'marks_awarded' => 'required|integer|min:0',
        'feedback' => 'nullable|string|max:1000'
    ]);

    $answer = QuizAnswer::with([
        'question.quiz.lesson.course',
        'attempt'
    ])->findOrFail($answer_id);

    // Only course owner can grade
    if (
        $answer->question->quiz->lesson->course->tutor_id
        !== auth()->id()
    ) {
        return response()->json([
            'message' => 'Unauthorized'
        ], 403);
    }

    // Only essays are graded manually
    if ($answer->question->type !== 'essay') {
        return response()->json([
            'message' => 'Only essay questions can be graded manually'
        ], 400);
    }

    // Prevent double grading
    if ($answer->graded_at) {
        return response()->json([
            'message' => 'This answer has already been graded'
        ], 400);
    }

    DB::beginTransaction();

    try {

        // Don't allow marks above question score
        $maxPoints = $answer->question->points;
        $marks = min($request->marks_awarded, $maxPoints);

        $answer->update([
            'marks_awarded' => $marks,
            'feedback' => $request->feedback,
            'graded_by' => auth()->id(),
            'graded_at' => now()
        ]);

        // Recalculate attempt score
        $attempt = $answer->attempt;

        $totalScore = $attempt->answers()
            ->sum('marks_awarded');

        // Check if any essay answers remain ungraded
        $pendingEssays = $attempt->answers()
            ->whereHas('question', function ($query) {
                $query->where('type', 'essay');
            })
            ->whereNull('graded_at')
            ->count();

        $attempt->update([
            'score' => $totalScore,
            'status' => $pendingEssays > 0
                ? 'pending_grading'
                : 'graded'
        ]);

        DB::commit();

        return response()->json([
            'message' => 'Answer graded successfully',
            'marks_awarded' => $marks,
            'total_score' => $totalScore,
            'status' => $attempt->fresh()->status
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'message' => 'Grading failed',
            'error' => $e->getMessage()
        ], 500);
    }
}
   public function update(Request $request, $lesson_id)
{
    $validator = Validator::make($request->all(), [
        'title' => 'required|string|max:255',
        'duration_minutes' => 'required|integer|min:1',

        'questions' => 'required|array|min:1',

        'questions.*.type' => 'required|in:mcq,boolean,essay',
        'questions.*.question_text' => 'required|string',
        'questions.*.options' => 'required_if:questions.*.type,mcq|array|min:2',
        'questions.*.correct_answer' => 'required_if:questions.*.type,mcq,boolean|string',
        'questions.*.points' => 'required|integer|min:1'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'errors' => $validator->errors()
        ], 422);
    }

    // Verify lesson belongs to a course owned by this tutor
    $lesson = Lesson::where('id', $lesson_id)
        ->whereHas('course', function ($q) {
            $q->where('tutor_id', auth()->id());
        })
        ->first();

    if (!$lesson) {
        return response()->json([
            'message' => 'Unauthorized'
        ], 403);
    }

    $quiz = Quiz::where('lesson_id', $lesson_id)->first();

    if (!$quiz) {
        return response()->json([
            'message' => 'Quiz not found'
        ], 404);
    }

    // Prevent editing after students have attempted the quiz
    if ($quiz->attempts()->exists()) {
        return response()->json([
            'message' => 'Cannot edit quiz after students have attempted it'
        ], 400);
    }

    DB::beginTransaction();

    try {

        // Validate boolean answers and convert formats
        $questions = $request->questions;
        foreach ($questions as &$q) {
            if ($q['type'] === 'boolean') {
                $correctAnswer = $q['correct_answer'];
                
                // Convert boolean/numeric values to Yes/No
                if (is_bool($correctAnswer)) {
                    $correctAnswer = $correctAnswer ? 'Yes' : 'No';
                } elseif (is_numeric($correctAnswer)) {
                    $correctAnswer = $correctAnswer == 1 ? 'Yes' : 'No';
                } elseif (is_string($correctAnswer)) {
                    // Accept "Yes"/"No", "yes"/"no", "true"/"false", "1"/"0"
                    $lower = strtolower($correctAnswer);
                    if (in_array($lower, ['yes', 'true', '1'])) {
                        $correctAnswer = 'Yes';
                    } elseif (in_array($lower, ['no', 'false', '0'])) {
                        $correctAnswer = 'No';
                    }
                }
                
                if (!in_array($correctAnswer, ['Yes', 'No'])) {
                    return response()->json([
                        'message' => 'Boolean correct_answer must be Yes or No (or true/false, 1/0, yes/no)'
                    ], 422);
                }
                
                $q['correct_answer'] = $correctAnswer;
            }
        }
        unset($q);

        // Update quiz info
        $quiz->update([
            'title' => $request->title,
            'duration_minutes' => $request->duration_minutes
        ]);

        // Remove old questions
        $quiz->questions()->delete();

        // Recreate questions
        foreach ($questions as $q) {

            $options = null;

            if ($q['type'] === 'boolean') {
                $options = json_encode(['Yes', 'No']);
            } elseif (isset($q['options'])) {
                $options = json_encode($q['options']);
            }

            $quiz->questions()->create([
                'question_text' => $q['question_text'],
                'type' => $q['type'],
                'options' => $options,

                'correct_answer' => in_array(
                    $q['type'],
                    ['mcq', 'boolean']
                )
                    ? $q['correct_answer']
                    : null,

                'points' => $q['points']
            ]);
        }

        DB::commit();

        return response()->json([
            'message' => 'Quiz updated successfully',
            'quiz_id' => $quiz->id
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'message' => 'Update failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * GET /api/student/lessons/{lesson_id}/quiz
     * Get quiz for a specific lesson for student viewing.
     */
    public function getForStudent($lesson_id)
    {
        $lesson = Lesson::find($lesson_id);

        if (!$lesson) {
            return response()->json([
                'message' => 'Lesson not found'
            ], 404);
        }

        $quiz = Quiz::with(['questions'])
            ->where('lesson_id', $lesson_id)
            ->first();

        if (!$quiz) {
            return response()->json([
                'message' => 'No quiz found for this lesson'
            ], 404);
        }

        $questions = $quiz->questions->map(function ($q) {
            return [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'type' => $q->type,
                'options' => $q->options ? json_decode($q->options, true) : null,
                'correct_answer' => $q->correct_answer,
                'points' => $q->points
            ];
        });

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'lesson_id' => $quiz->lesson_id,
                'title' => $quiz->title,
                'duration_minutes' => $quiz->duration_minutes,
                'created_by' => $quiz->created_by,
                'created_at' => $quiz->created_at?->format('M d, Y')
            ],
            'questions' => $questions
        ]);
    }

    /**
     * GET /api/tutor/lessons/{lesson_id}/quiz
     * Get quiz for a specific lesson
     */
    public function getByLesson($lesson_id)
    {
        $lesson = Lesson::where('id', $lesson_id)
            ->whereHas('course', function ($q) {
                $q->where('tutor_id', auth()->id());
            })
            ->first();

        if (!$lesson) {
            return response()->json([
                'message' => 'Lesson not found or unauthorized'
            ], 404);
        }

        $quiz = Quiz::with(['questions'])
            ->where('lesson_id', $lesson_id)
            ->first();

        if (!$quiz) {
            return response()->json([
                'message' => 'No quiz found for this lesson'
            ], 404);
        }

        $questions = $quiz->questions->map(function ($q) {
            return [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'type' => $q->type,
                'options' => $q->options ? json_decode($q->options, true) : null,
                'correct_answer' => $q->correct_answer,
                'points' => $q->points
            ];
        });

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'lesson_id' => $quiz->lesson_id,
                'title' => $quiz->title,
                'duration_minutes' => $quiz->duration_minutes,
                'created_by' => $quiz->created_by,
                'created_at' => $quiz->created_at->format('M d, Y')
            ],
            'questions' => $questions
        ]);
    }
}
