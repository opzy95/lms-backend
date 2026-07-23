<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveClass;
use App\Models\LiveAttendance;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class LiveClassController extends Controller
{
    // ==================== TUTOR ENDPOINTS ====================

    /**
     * GET /api/tutor/live-classes - List all live classes for tutor
     */
    public function tutorIndex()
    {
        $tutorId = Auth::id();

        $classes = LiveClass::where('tutor_id', $tutorId)
            ->orderBy('start_time', 'desc')
            ->get()
            ->map(function ($class) {
                return $this->formatLiveClassResponse($class);
            });

        return response()->json([
            'message' => 'Live classes retrieved successfully',
            'data' => $classes
        ]);
    }

    /**
     * POST /api/tutor/live-classes - Create new live class session
     */
    public function tutorStore(Request $request)
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'required|date|after:now',
        ]);

        // Verify tutor owns this course
        $course = $request->user()->tutorCourses()
            ->where('id', $request->course_id)
            ->first();

        if (!$course) {
            return response()->json([
                'message' => 'Unauthorized. You do not own this course.'
            ], 403);
        }

        $liveClass = LiveClass::create([
            'course_id' => $request->course_id,
            'tutor_id' => Auth::id(),
            'title' => $request->title,
            'description' => $request->description,
            'room_name' => 'ife-lms-' . $request->course_id . '-' . Str::uuid(),
            'start_time' => $request->start_time,
            'status' => 'scheduled'
        ]);

        return response()->json([
            'message' => 'Live class created successfully',
            'data' => $this->formatLiveClassResponse($liveClass)
        ], 201);
    }

    /**
     * PUT /api/tutor/live-classes/{id}/start - Start live class
     */
    public function tutorStart($id)
    {
        $liveClass = LiveClass::findOrFail($id);

        if ($liveClass->tutor_id !== Auth::id()) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        if ($liveClass->status === 'ended') {
            return response()->json([
                'message' => 'Cannot start an ended class'
            ], 400);
        }

        $liveClass->update([
            'status' => 'live',
            'start_time' => now()
        ]);

        return response()->json([
            'message' => 'Class started successfully',
            'data' => $this->formatLiveClassResponse($liveClass)
        ]);
    }

    /**
     * PUT /api/tutor/live-classes/{id}/end - End live class
     */
    public function tutorEnd($id)
    {
        $liveClass = LiveClass::findOrFail($id);

        if ($liveClass->tutor_id !== Auth::id()) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        if ($liveClass->status === 'ended') {
            return response()->json([
                'message' => 'Class is already ended'
            ], 400);
        }

        // Mark all remaining students as left
        $liveClass->attendances()
            ->whereNull('left_at')
            ->each(function ($attendance) {
                $attendance->markAsLeft();
            });

        $liveClass->update([
            'status' => 'ended',
            'end_time' => now()
        ]);

        return response()->json([
            'message' => 'Class ended successfully',
            'data' => $this->formatLiveClassResponse($liveClass)
        ]);
    }

    /**
     * DELETE /api/tutor/live-classes/{id} - Delete scheduled class
     */
    public function tutorDestroy($id)
    {
        $liveClass = LiveClass::findOrFail($id);

        if ($liveClass->tutor_id !== Auth::id()) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        if ($liveClass->status !== 'scheduled') {
            return response()->json([
                'message' => 'Can only delete scheduled classes'
            ], 400);
        }

        $liveClass->delete();

        return response()->json([
            'message' => 'Class deleted successfully'
        ]);
    }

    /**
     * GET /api/tutor/live-classes/{id}/attendance - View attendance
     */
    public function tutorAttendance($id)
    {
        $liveClass = LiveClass::findOrFail($id);

        if ($liveClass->tutor_id !== Auth::id()) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $attendances = $liveClass->attendances()
            ->with('student:id,name,email')
            ->get()
            ->map(function ($attendance) {
                return [
                    'student_id' => $attendance->student->id,
                    'student_name' => $attendance->student->name,
                    'student_email' => $attendance->student->email,
                    'joined_at' => $attendance->joined_at,
                    'left_at' => $attendance->left_at,
                    'duration_minutes' => $attendance->duration_minutes,
                    'status' => $attendance->left_at ? 'completed' : 'active'
                ];
            });

        return response()->json([
            'message' => 'Attendance retrieved successfully',
            'class_title' => $liveClass->title,
            'total_students' => $attendances->count(),
            'data' => $attendances
        ]);
    }

    // ==================== STUDENT ENDPOINTS ====================

    /**
     * GET /api/student/live-classes - List live classes for enrolled courses
     */
    public function studentIndex(Request $request)
    {
        $userId = Auth::id();

        // Get all courses the student is enrolled in
        $enrolledCourseIds = Enrollment::where('user_id', $userId)
            ->pluck('course_id')
            ->toArray();

        if (empty($enrolledCourseIds)) {
            return response()->json([
                'message' => 'You are not enrolled in any courses',
                'data' => []
            ]);
        }

        $classes = LiveClass::whereIn('course_id', $enrolledCourseIds)
            ->orderBy('start_time', 'desc')
            ->get()
            ->map(function ($class) use ($userId) {
                return $this->formatLiveClassResponseForStudent($class, $userId);
            });

        return response()->json([
            'message' => 'Available live classes retrieved successfully',
            'data' => $classes
        ]);
    }

    /**
     * GET /api/student/live-classes/status/{status} - Filter by status
     */
    public function studentIndexByStatus($status, Request $request)
    {
        $userId = Auth::id();
        $validStatuses = ['scheduled', 'live', 'ended'];

        if (!in_array($status, $validStatuses)) {
            return response()->json([
                'message' => 'Invalid status. Use: scheduled, live, or ended'
            ], 400);
        }

        $enrolledCourseIds = Enrollment::where('user_id', $userId)
            ->pluck('course_id')
            ->toArray();

        if (empty($enrolledCourseIds)) {
            return response()->json([
                'message' => 'You are not enrolled in any courses',
                'data' => []
            ]);
        }

        $classes = LiveClass::whereIn('course_id', $enrolledCourseIds)
            ->where('status', $status)
            ->orderBy('start_time', 'desc')
            ->get()
            ->map(function ($class) use ($userId) {
                return $this->formatLiveClassResponseForStudent($class, $userId);
            });

        return response()->json([
            'message' => "Live classes with status '{$status}' retrieved successfully",
            'status' => $status,
            'data' => $classes
        ]);
    }

    /**
     * POST /api/student/live-classes/{id}/join - Join live class
     */
    public function studentJoin($id, Request $request)
    {
        $userId = Auth::id();
        $liveClass = LiveClass::findOrFail($id);

        // Check if student is enrolled in the course
        $isEnrolled = Enrollment::where('user_id', $userId)
            ->where('course_id', $liveClass->course_id)
            ->exists();

        if (!$isEnrolled) {
            return response()->json([
                'message' => 'You are not enrolled in this course'
            ], 403);
        }

        // Check if class status allows joining
        if ($liveClass->status === 'ended') {
            return response()->json([
                'message' => 'This class has ended'
            ], 410);
        }

        if ($liveClass->status === 'scheduled') {
            return response()->json([
                'message' => 'This class has not started yet'
            ], 400);
        }

        // Check if student already joined
        $existingAttendance = LiveAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();

        if ($existingAttendance) {
            return response()->json([
                'message' => 'You are already in this class',
                'data' => $this->formatLiveClassResponseForStudent($liveClass, $userId)
            ]);
        }

        // Record attendance
        LiveAttendance::create([
            'live_class_id' => $liveClass->id,
            'user_id' => $userId,
            'joined_at' => now()
        ]);

        return response()->json([
            'message' => 'Successfully joined the live class',
            'data' => $this->formatLiveClassResponseForStudent($liveClass, $userId)
        ], 201);
    }

    /**
     * POST /api/student/live-classes/{id}/leave - Leave live class
     */
    public function studentLeave($id)
    {
        $userId = Auth::id();
        $liveClass = LiveClass::findOrFail($id);

        $attendance = LiveAttendance::where('live_class_id', $liveClass->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();

        if (!$attendance) {
            return response()->json([
                'message' => 'You are not currently in this class'
            ], 400);
        }

        $attendance->markAsLeft();

        return response()->json([
            'message' => 'Successfully left the live class',
            'duration_minutes' => $attendance->duration_minutes
        ]);
    }

    /**
     * GET /api/student/live-classes/{id} - Get live class details
     */
    public function studentShow($id)
    {
        $userId = Auth::id();
        $liveClass = LiveClass::findOrFail($id);

        // Check enrollment
        $isEnrolled = Enrollment::where('user_id', $userId)
            ->where('course_id', $liveClass->course_id)
            ->exists();

        if (!$isEnrolled) {
            return response()->json([
                'message' => 'You are not enrolled in this course'
            ], 403);
        }

        return response()->json([
            'message' => 'Live class details retrieved successfully',
            'data' => $this->formatLiveClassResponseForStudent($liveClass, $userId)
        ]);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Format live class response for tutor
     */
    private function formatLiveClassResponse($liveClass)
    {
        return [
            'id' => $liveClass->id,
            'course_id' => $liveClass->course_id,
            'title' => $liveClass->title,
            'description' => $liveClass->description,
            'room_name' => $liveClass->room_name,
            'jitsi_domain' => env('JITSI_DOMAIN', 'meet.jit.si'),
            'status' => $liveClass->status,
            'start_time' => $liveClass->start_time,
            'end_time' => $liveClass->end_time,
            'active_students' => $liveClass->getActiveStudents()->count(),
            'total_attendees' => $liveClass->attendances()->count(),
            'created_at' => $liveClass->created_at
        ];
    }

    /**
     * Format live class response for student
     */
    private function formatLiveClassResponseForStudent($liveClass, $userId)
    {
        $isInClass = $liveClass->isStudentInClass($userId);

        return [
            'id' => $liveClass->id,
            'course_id' => $liveClass->course_id,
            'title' => $liveClass->title,
            'description' => $liveClass->description,
            'room_name' => $liveClass->room_name,
            'jitsi_domain' => env('JITSI_DOMAIN', 'meet.jit.si'),
            'status' => $liveClass->status,
            'start_time' => $liveClass->start_time,
            'end_time' => $liveClass->end_time,
            'tutor' => [
                'id' => $liveClass->tutor->id,
                'name' => $liveClass->tutor->name,
                'email' => $liveClass->tutor->email
            ],
            'is_in_class' => $isInClass,
            'active_students' => $liveClass->getActiveStudents()->count(),
            'created_at' => $liveClass->created_at
        ];
    }
}
