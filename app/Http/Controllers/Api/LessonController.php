<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class LessonController extends Controller
{
    // POST /api/tutor/courses/{course}/lessons
    public function store(Request $request, Course $course)
    {
        // Only course owner can add lessons
        if ($course->tutor_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:video,text,file,quiz',
            'video_url' => 'nullable|url|required_if:type,video',
            'content' => 'nullable|string|required_if:type,text',
            'file' => 'nullable|file|mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/zip,application/x-zip-compressed|max:51200|required_if:type,file',
            'order' => 'nullable|integer|min:0'
        ]);

        if ($validator->fails()) {
            // Custom message for file size
            if ($request->hasFile('file') && $request->file('file')->getSize() > 50 * 1024 * 1024) {
                return response()->json(['message' => 'File too large. Max upload size is 50MB'], 413);
            }
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $filePath = null;
        $fileName = null;

        if ($request->type === 'file' && $request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();
            $filePath = $file->store('course_files', 'public');
        }

        $lesson = $course->lessons()->create([
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type,
            'video_url' => $request->type === 'video' ? $request->video_url : null,
            'content' => $request->type === 'text' ? $request->content : null,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'order' => $request->order ?? 0
        ]);

        return response()->json([
            'message' => 'Lesson created successfully',
            'lesson' => $lesson
        ], 201);
    }

    // GET /api/tutor/courses/{course}/lessons or /api/student/courses/{course}/lessons
    public function index(Course $course)
    {
        $user = auth()->user();

        // Check if user is tutor (owner) or student (enrolled)
        if ($course->tutor_id === $user->id) {
            // Tutor view - show all lessons with full details
            $lessons = $course->lessons()->orderBy('order')->get();
            return response()->json(['lessons' => $lessons]);
        } else {
            // Student view - check enrollment
            $enrolled = $course->enrollments()->where('user_id', $user->id)->exists();
            if (!$enrolled) {
                return response()->json(['message' => 'You are not enrolled in this course'], 403);
            }

            $lessons = $course->lessons()->orderBy('order')->get(['id', 'title', 'description', 'type', 'file_name', 'video_url', 'order']);
            return response()->json([
                'course_id' => $course->id,
                'course_title' => $course->title,
                'lessons' => $lessons
            ]);
        }
    }

    // PUT /api/tutor/lessons/{lesson}
    public function update(Request $request, Lesson $lesson)
    {
        if ($lesson->course->tutor_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->only(['title', 'description', 'order']);

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
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Delete file from storage
        if ($lesson->file_path) {
            Storage::disk('public')->delete($lesson->file_path);
        }

        $lesson->delete();
        return response()->json(['message' => 'Lesson deleted']);
    }

    // GET /api/student/lessons/{lesson}/download
    public function download(Lesson $lesson)
    {
        // Verify student is enrolled in the course
        $userId = auth()->id();
        $isOwner = $lesson->course->tutor_id === $userId;
        $isEnrolled = auth()->user()->enrollments()->where('course_id', $lesson->course_id)->exists();

        if (!$isOwner && !$isEnrolled) {
            return response()->json(['message' => 'Not enrolled in this course'], 403);
        }

        // Check if lesson has a file
        if (!$lesson->file_path) {
            return response()->json(['message' => 'No file available for this lesson'], 404);
        }

        // Check if file exists in storage
        if (!Storage::disk('public')->exists($lesson->file_path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return Storage::disk('public')->download($lesson->file_path, $lesson->file_name);
    }
}
