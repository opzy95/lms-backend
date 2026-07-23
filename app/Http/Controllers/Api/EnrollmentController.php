<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnrollmentController extends Controller
{
   public function enroll($course_id)
{
    $user = auth()->user();
    
    // Find the course
    $course = Course::find($course_id);
    
    if (!$course) {
        return response()->json([
            'message' => 'Course not found'
        ], 404);
    }

    // Tutor cannot enroll in own course
    if ($course->tutor_id === $user->id) {
        return response()->json([
            'message' => 'You cannot enroll in your own course'
        ], 400);
    }

    // Student can only enroll in courses matching their education level
    if ($user->role === 'student' && $course->education_level !== $user->education_level) {
        return response()->json([
            'message' => 'You can only enroll in ' . $user->education_level . ' level courses'
        ], 403);
    }

    // Prevent duplicate enrollment
    $alreadyEnrolled = Enrollment::where('user_id', $user->id)
        ->where('course_id', $course_id)
        ->exists();

    if ($alreadyEnrolled) {
        return response()->json([
            'message' => 'Already enrolled in this course'
        ], 409);
    }

    $enrollment = Enrollment::create([
        'user_id' => $user->id,
        'course_id' => $course_id,
        'enrolled_at' => now()
    ]);

    return response()->json([
        'message' => 'Enrolled successfully',
        'enrollment' => $enrollment
    ], 201);
}
}
