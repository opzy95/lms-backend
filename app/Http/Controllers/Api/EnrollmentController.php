<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnrollmentController extends Controller
{
    public function enroll($course_id)
    {
        $user = Auth::user();

        $course = Course::find($course_id);
        if (!$course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        if (Enrollment::where('user_id', $user->id)->where('course_id', $course_id)->exists()) {
            return response()->json(['message' => 'Already enrolled in this course'], 409);
        }

        $enrollment = Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course_id,
            'enrolled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Enrolled successfully',
            'enrollment' => $enrollment,
        ], 201);
    }
}
