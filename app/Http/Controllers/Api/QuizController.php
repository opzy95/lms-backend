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
            ->where('tutor_id', auth()->id())->first();

        if (!$lesson) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $quiz = Quiz::create([
                'lesson_id' => $lesson_id,
                'title' => $request->title,
                'duration_minutes' => $request->duration_minutes,
                'created_by' => auth()->id()
            ]);

            foreach ($request->questions as $q) {
                if ($q['type'] == 'boolean') {
                    $q['options'] = ['Yes', 'No'];
                }

                Question::create([
                    'quiz_id' => $quiz->id,
                    'question_text' => $q['question_text'],
                    'type' => $q['type'],
                    'options' => isset($q['options']) ? json_encode($q['options']) : null,
                    'correct_answer' => $q['correct_answer'],
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
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // STUDENT: Start quiz - get questions without answers
    public function start($quiz_id)
    {
        $quiz = Quiz::with('questions')->findOrFail($quiz_id);

        // Prevent retake if already submitted
        $existing = QuizAttempt::where('quiz_id', $quiz_id)
            ->where('student_id', auth()->id())->first();
        if ($existing && $existing->submitted_at) {
            return response()->json(['message' => 'Quiz already submitted'], 400);
        }

        $attempt = QuizAttempt::firstOrCreate(
            ['quiz_id' => $quiz_id, 'student_id' => auth()->id()],
            ['started_at' => now()]
        );

        $questions = $quiz->questions->map(function ($q) {
            return [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'type' => $q->type,
                'options' => $q->options ? json_decode($q->options) : null,
                'points' => $q->points
            ];
        });

        return response()->json([
            'quiz' => ['id' => $quiz->id, 'title' => $quiz->title, 'duration' => $quiz->duration_minutes],
            'questions' => $questions
        ]);
    }

    // STUDENT: Submit answers
    public function submit(Request $request, $quiz_id)
    {
        $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'required|string'
        ]);

        $attempt = QuizAttempt::where('quiz_id', $quiz_id)
            ->where('student_id', auth()->id())->firstOrFail();

        if ($attempt->submitted_at) {
            return response()->json(['message' => 'Already submitted'], 400);
        }

        $autoScore = 0;
        $pendingEssay = false;

        DB::beginTransaction();
        try {
            foreach ($request->answers as $ans) {
                $q = Question::find($ans['question_id']);
                $marks = 0;
                $isCorrect = false;

                if (in_array($q->type, ['mcq', 'boolean'])) {
                    $isCorrect = $q->correct_answer == $ans['answer'];
                    $marks = $isCorrect ? $q->points : 0;
                    $autoScore += $marks;
                } else {
                    $pendingEssay = true; // essay needs tutor grading
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
                'status' => $pendingEssay ? 'pending_grading' : 'graded'
            ]);

            DB::commit();

            return response()->json([
                'message' => $pendingEssay
                    ? 'Submitted. Essays pending tutor grading'
                    : 'Submitted successfully',
                'score' => $autoScore
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Submit failed'], 500);
        }
    }
    // TUTOR: Grade essay answer
    public function gradeAnswer(Request $request, $answer_id)
    {
        $request->validate([
            'marks_awarded' => 'required|integer|min:0',
            'feedback' => 'nullable|string|max:1000'
        ]);

        $answer = QuizAnswer::with(['question.quiz.lesson', 'attempt'])->findOrFail($answer_id);

        // Only tutor who created the quiz can grade
        if ($answer->question->quiz->lesson->tutor_id != auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Only essay questions can be manually graded
        if ($answer->question->type != 'essay') {
            return response()->json(['message' => 'Only essay questions can be graded manually'], 400);
        }

        // Cap marks to question points
        $maxPoints = $answer->question->points;
        $marks = min($request->marks_awarded, $maxPoints);

        $answer->update([
            'marks_awarded' => $marks,
            'feedback' => $request->feedback,
            'graded_by' => auth()->id(),
            'graded_at' => now()
        ]);

        // Recalculate total score for this attempt
        $attempt = $answer->attempt;
        $totalScore = $attempt->answers()->sum('marks_awarded');

        // Check if all essays are graded
        $pendingEssays = $attempt->answers()
            ->whereHas('question', fn($q) => $q->where('type', 'essay'))
            ->whereNull('graded_at')->count();

        $attempt->update([
            'score' => $totalScore,
            'status' => $pendingEssays > 0 ? 'pending_grading' : 'graded'
        ]);

        return response()->json([
            'message' => 'Answer graded',
            'marks_awarded' => $marks,
            'total_score' => $totalScore,
            'status' => $attempt->status
        ]);
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

        if ($validator->fails()) return response()->json($validator->errors(), 422);

        $lesson = Lesson::where('id', $lesson_id)
            ->where('tutor_id', auth()->id())->first();
        if (!$lesson) return response()->json(['message' => 'Unauthorized'], 403);

        $quiz = Quiz::where('lesson_id', $lesson_id)->first();
        if (!$quiz) return response()->json(['message' => 'Quiz not found'], 404);

        DB::beginTransaction();
        try {
            // Update quiz meta
            $quiz->update([
                'title' => $request->title,
                'duration_minutes' => $request->duration_minutes
            ]);

            // Simplest: delete old questions + recreate. 
            // For production you’d diff + update to keep attempts, but this works for now.
            $quiz->questions()->delete();

            foreach ($request->questions as $q) {
                $quiz->questions()->create([
                    'type' => $q['type'],
                    'question_text' => $q['question_text'],
                    'options' => isset($q['options']) ? json_encode($q['options']) : null,
                    'correct_answer' => $q['type'] === 'mcq' ? $q['correct_answer'] : null,
                    'points' => $q['points']
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Quiz updated', 'quiz_id' => $quiz->id]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Update failed'], 500);
        }
    }
}
