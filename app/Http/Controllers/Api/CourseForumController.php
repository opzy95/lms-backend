<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseForumReply;
use App\Models\CourseForumThread;
use App\Models\Enrollment;
use Illuminate\Http\Request;

class CourseForumController extends Controller
{
    /**
     * Get badge based on role.
     */
    private function badge(string $role): string
    {
        return str_contains(strtolower($role), 'tutor')
            ? 'Tutor'
            : 'Student';
    }

    /**
     * Check whether user is enrolled or is the course tutor.
     */
    private function isEnrolledOrTutor(int $userId, int $courseId): bool
    {
        $isEnrolled = Enrollment::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->exists();
        
        $isTutor = Course::where('id', $courseId)
                ->where('tutor_id', $userId)
                ->exists();
        
        \Log::info('isEnrolledOrTutor check', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'is_enrolled' => $isEnrolled,
            'is_tutor' => $isTutor,
        ]);
        
        return $isEnrolled || $isTutor;
    }

    /**
     * GET /api/courses/{course_id}/forum
     */
    public function index($course_id)
    {
        $userId = auth()->id();
        \Log::info($userId);
        // Debug logging
        \Log::info('Forum index access attempt', [
            'user_id' => $userId,
            'course_id' => $course_id,
            'user' => auth()->user(),
        ]);

        if (!$userId) {
            return response()->json([
                'message' => 'Unauthorized. Please login first.'
            ], 401);
        }

        if (!$this->isEnrolledOrTutor($userId, $course_id)) {
            \Log::warning('Forum access denied - not enrolled or tutor', [
                'user_id' => $userId,
                'course_id' => $course_id,
            ]);
            return response()->json([
                'message' => 'Access denied.'
            ], 403);
        }

        $threads = CourseForumThread::with([
            'user:id,name,role',
            'lesson:id,title',
            'replies' => function ($query) {
                $query->oldest()->with('user:id,name,role');
            }
        ])
        ->where('course_id', $course_id)
        ->latest()
        ->paginate(20);

        $forum = $threads->getCollection()
            ->groupBy('lesson_id')
            ->map(function ($lessonThreads) {

                return [
                    'lesson_id' => $lessonThreads->first()->lesson_id,
                    'lesson_title' => optional($lessonThreads->first()->lesson)->title,

                    'threads' => $lessonThreads->map(function ($thread) {

                        return [

                            'thread_id' => $thread->id,

                            'question' => $thread->question,

                            'asked_by' => $thread->user->name,

                            'asked_role' => $thread->user->role,

                            'asked_badge' => $this->badge($thread->user->role),

                            'created_at' => $thread->created_at,

                            'replies' => $thread->replies->map(function ($reply) {

                                return [

                                    'reply_id' => $reply->id,

                                    'answer' => $reply->answer,

                                    'replied_by' => $reply->user->name,

                                    'replied_role' => $reply->user->role,

                                    'badge' => $this->badge($reply->user->role),

                                    'created_at' => $reply->created_at,

                                ];

                            })->values()

                        ];

                    })->values()

                ];

            })->values();

        return response()->json([

            'course_id' => $course_id,

            'forum' => $forum,

            'pagination' => [

                'current_page' => $threads->currentPage(),

                'last_page' => $threads->lastPage(),

                'total' => $threads->total(),

            ]

        ]);
    }

    /**
     * POST /api/courses/{course_id}/forum/ask
     */
    public function ask(Request $request, $course_id)
    {
        $userId = auth()->id();
        $user = auth()->user();

        // Check if user is either enrolled or is the tutor
        $isEnrolled = Enrollment::where('user_id', $userId)
            ->where('course_id', $course_id)
            ->exists();

        $isTutor = Course::where('id', $course_id)
            ->where('tutor_id', $userId)
            ->exists();

        if (!$isEnrolled && !$isTutor) {
            return response()->json([
                'message' => 'Only enrolled students or course tutors can post in the forum.'
            ], 403);
        }

        $request->validate([
            'lesson_id' => 'required|exists:lessons,id',
            'question' => 'required|string|min:5|max:1000',
        ]);

        // Make sure lesson belongs to this course
        $course = Course::findOrFail($course_id);

        $lesson = $course->lessons()
            ->where('id', $request->lesson_id)
            ->first();

        if (!$lesson) {
            return response()->json([
                'message' => 'Selected lesson does not belong to this course.'
            ], 422);
        }

        $thread = CourseForumThread::create([
            'course_id' => $course_id,
            'lesson_id' => $request->lesson_id,
            'user_id' => $userId,
            'question' => $request->question,
        ]);

        $thread->load('user:id,name,role');

        return response()->json([
            'message' => 'Question posted successfully.',
            'thread' => [
                'id' => $thread->id,
                'question' => $thread->question,
                'asked_by' => $thread->user->name,
                'badge' => $this->badge($thread->user->role),
                'created_at' => $thread->created_at,
            ]
        ], 201);
    }

    /**
     * POST /api/forum/{thread_id}/reply
     */
    public function reply(Request $request, $thread_id)
    {
        $userId = auth()->id();

        $thread = CourseForumThread::with([
            'course:id,tutor_id'
        ])->find($thread_id);

        if (!$thread) {

            return response()->json([

                'message' => 'Thread not found.'

            ], 404);

        }

        $canReply =

            $thread->course->tutor_id == $userId ||

            Enrollment::where('user_id', $userId)
                ->where('course_id', $thread->course_id)
                ->exists();

        if (!$canReply) {

            return response()->json([

                'message' => 'Access denied.'

            ], 403);

        }

        $request->validate([

            'answer' => 'required|string|min:2|max:2000',

        ]);

        $reply = CourseForumReply::create([

            'thread_id' => $thread->id,

            'user_id' => $userId,

            'answer' => $request->answer,

        ]);

        $reply->load('user:id,name,role');

        return response()->json([

            'message' => 'Reply posted successfully.',

            'reply' => [

                'id' => $reply->id,

                'answer' => $reply->answer,

                'replied_by' => $reply->user->name,

                'role' => $reply->user->role,

                'badge' => $this->badge($reply->user->role),

                'created_at' => $reply->created_at,

            ]

        ], 201);
    }
}