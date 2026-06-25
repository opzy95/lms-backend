<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseForumThread;
use App\Models\CourseForumReply;
use App\Models\Enrollment;
use App\Models\Course;
use Illuminate\Http\Request;

class CourseForumController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    private function badge(string $role): string
    {
        return str_contains($role, 'tutor') ? 'Tutor' : 'Student';
    }

    private function isEnrolledOrTutor(int $userId, int $courseId): bool
    {
        return Enrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->exists()
            || Course::where('id', $courseId)
                ->where('tutor_id', $userId)
                ->exists();
    }

    // GET /api/courses/{course_id}/forum
    public function index($course_id)
    {
        $user_id = auth()->id();

        if (!$this->isEnrolledOrTutor($user_id, $course_id)) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        $threads = CourseForumThread::with([
                'user:id,name,role',
                'lesson:id,title',
                'replies.user:id,name,role'
            ])
            ->where('course_id', $course_id)
            ->latest()
            ->paginate(20); // avoid loading unbounded data

        $forum = $threads->getCollection()
            ->groupBy('lesson_id')
            ->map(function ($lessonThreads) {
                return [
                    'lesson_id' => $lessonThreads->first()->lesson_id,
                    'lesson_title' => $lessonThreads->first()->lesson->title ?? null,
                    'threads' => $lessonThreads->map(fn ($thread) => [
                        'thread_id' => $thread->id,
                        'question' => $thread->question,
                        'asked_by' => $thread->user->name,
                        'asked_role' => $thread->user->role,
                        'asked_badge' => $this->badge($thread->user->role),
                        'replies' => $thread->replies->map(fn ($reply) => [
                            'reply_id' => $reply->id,
                            'answer' => $reply->answer,
                            'replied_by' => $reply->user->name,
                            'replied_role' => $reply->user->role,
                            'badge' => $this->badge($reply->user->role),
                            'created_at' => $reply->created_at,
                        ]),
                    ])->values(),
                ];
            })->values();

        return response()->json([
            'course_id' => $course_id,
            'forum' => $forum,
            'pagination' => [
                'current_page' => $threads->currentPage(),
                'last_page' => $threads->lastPage(),
                'total' => $threads->total(),
            ],
        ]);
    }

    // POST /api/courses/{course_id}/forum/ask
    public function ask(Request $request, $course_id)
    {
        $user_id = auth()->id();

        if (!Enrollment::where('user_id', $user_id)->where('course_id', $course_id)->exists()) {
            return response()->json(['message' => 'Not enrolled'], 403);
        }

        $request->validate([
            'lesson_id' => 'required|exists:lessons,id',
            'question' => 'required|string|min:5',
        ]);

        $thread = CourseForumThread::create([
            'course_id' => $course_id,
            'lesson_id' => $request->lesson_id,
            'user_id' => $user_id,
            'question' => $request->question,
        ]);

        return response()->json(['thread' => $thread->load('user:id,name')], 201);
    }

    // POST /api/forum/{thread_id}/reply
    public function reply(Request $request, $thread_id)
    {
        $user_id = auth()->id();

        $thread = CourseForumThread::with('course:id,tutor_id')->find($thread_id);
        if (!$thread) {
            return response()->json(['message' => 'Thread not found'], 404);
        }

        $canAccess = $thread->course->tutor_id == $user_id
            || Enrollment::where('user_id', $user_id)->where('course_id', $thread->course_id)->exists();
        if (!$canAccess) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        $request->validate(['answer' => 'required|string|min:2']);

        $reply = CourseForumReply::create([
            'thread_id' => $thread_id,
            'user_id' => $user_id,
            'answer' => $request->answer,
        ]);

        return response()->json(['reply' => $reply->load('user:id,name')], 201);
    }
}