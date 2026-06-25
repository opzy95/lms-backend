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

    // GET /api/courses/{course_id}/forum
    // Show all Q&A for the course, grouped by lesson
    public function index($course_id)
    {
        $user_id = auth()->id();
        
        // Only enrolled students or course tutor can view
        $canAccess = Enrollment::where('user_id', $user_id)->where('course_id', $course_id)->exists()
                   || Course::where('id', $course_id)->where('tutor_id', $user_id)->exists();
        if(!$canAccess) return response()->json(['message' => 'Access denied'], 403);

        $threads = CourseForumThread::with(['user:id,name', 'lesson:id,title', 'replies.user:id,name'])
            ->where('course_id', $course_id)
            ->latest()->get()
            ->groupBy('lesson_id'); // group by lesson

        return response()->json(['course_id' => $course_id, 'forum' => $threads]);
    }

    // POST /api/courses/{course_id}/forum/ask
    // Ask a question about a lesson
    public function ask(Request $request, $course_id)
    {
        $user_id = auth()->id();
        
        if(!Enrollment::where('user_id', $user_id)->where('course_id', $course_id)->exists())
            return response()->json(['message' => 'Not enrolled'], 403);

        $request->validate([
            'lesson_id' => 'required|exists:lessons,id',
            'question' => 'required|string|min:5'
        ]);

        $thread = CourseForumThread::create([
            'course_id' => $course_id,
            'lesson_id' => $request->lesson_id,
            'user_id' => $user_id,
            'question' => $request->question
        ]);

        return response()->json(['thread' => $thread->load('user:id,name')], 201);
    }

    // POST /api/forum/{thread_id}/reply
    // Tutor or any student replies
    public function reply(Request $request, $thread_id)
    {
        $user_id = auth()->id();
        $thread = CourseForumThread::with('course')->find($thread_id);
        if(!$thread) return response()->json(['message' => 'Thread not found'], 404);

        $canAccess = Enrollment::where('user_id', $user_id)->where('course_id', $thread->course_id)->exists()
                   || $thread->course->tutor_id == $user_id;
        if(!$canAccess) return response()->json(['message' => 'Access denied'], 403);

        $request->validate(['answer' => 'required|string|min:2']);

        $reply = CourseForumReply::create([
            'thread_id' => $thread_id,
            'user_id' => $user_id,
            'answer' => $request->answer
        ]);

        return response()->json(['reply' => $reply->load('user:id,name')], 201);
    }
}