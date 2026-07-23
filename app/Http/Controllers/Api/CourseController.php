<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CourseController extends Controller
{
    // POST /api/tutor/courses - Create course
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|in:free,paid',
            'price' => 'nullable|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $price = $request->type === 'free' ? 0 : $request->price;

        if ($request->type === 'paid' && $price <= 0) {
            return response()->json(['message' => 'Paid courses must have price > 0'], 422);
        }

        $tutor = $request->user();

        $course = Course::create([
            'tutor_id' => $tutor->id,
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type,
            'price' => $price,
            'education_level' => $tutor->education_level
        ]);

        return response()->json([
            'message' => 'Course created successfully',
            'course' => $course
        ], 201);
    }

    // GET /api/tutor/my-courses - List courses
    public function myCourses(Request $request)
    {
        $user = $request->user();
        
        $query = Course::query();
        
        // Tutors see only their courses. Admin sees all.
        if ($user->role === 'tutor') {
            $query->where('tutor_id', $user->id);
        }
        
        $courses = $query->latest()->get();
        return response()->json($courses);
    }

    // GET /api/tutor/courses-with-lessons - List courses with their lessons
    public function coursesWithLessons(Request $request)
    {
        $user = $request->user();
        
        $courses = Course::where('tutor_id', $user->id)
            ->with(['lessons' => function ($query) {
                $query->orderBy('order', 'asc');
            }])
            ->latest()
            ->get();
        
        return response()->json([
            'message' => 'Courses with lessons retrieved successfully',
            'data' => $courses,
            'total' => $courses->count()
        ]);
    }

    // PUT /api/tutor/courses/{id} - Update course
    public function update(Request $request, $id)
    {
        $user = $request->user();
        
        $query = Course::where('id', $id);
        if ($user->role === 'tutor') {
            $query->where('tutor_id', $user->id); // tutor can only edit own
        }
        
        $course = $query->first();
        if (!$course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $data = $request->only(['title', 'description', 'type', 'price']);
        
        if (isset($data['type']) && $data['type'] === 'free') {
            $data['price'] = 0;
        }
        if (isset($data['type']) && $data['type'] === 'paid' && ($data['price'] ?? $course->price) <= 0) {
            return response()->json(['message' => 'Paid courses must have price > 0'], 422);
        }

        $course->update($data);
        
        return response()->json(['message' => 'Course updated', 'course' => $course]);
    }

    // DELETE /api/tutor/courses/{id} - Delete course
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        
        $query = Course::where('id', $id);
        if ($user->role === 'tutor') {
            $query->where('tutor_id', $user->id); // tutor can only delete own
        }
        
        $course = $query->first();
        if (!$course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $course->delete();
        return response()->json(['message' => 'Course deleted']);
    }
    public function show(Request $request, $id)
{
    $user = $request->user();
    
    $query = Course::where('id', $id);
    if ($user->role === 'tutor') {
        $query->where('tutor_id', $user->id);
    }
    
    $course = $query->first();
    if (!$course) {
        return response()->json(['message' => 'Course not found'], 404);
    }

    return response()->json($course);
}
public function index(Request $request)
{
    $user = $request->user();
    
    $query = Course::query()->latest();
    
    // If user is a student, filter by their education level
    if ($user && $user->role === 'student' && $user->education_level) {
        $query->where('education_level', $user->education_level);
    }
    
    $courses = $query->get(['id','title','description','type','price','education_level','tutor_id','created_at']);
    return response()->json($courses);
}

// GET /api/courses/{id} - Public single course
public function showPublic($id)
{
    $course = Course::find($id, ['id','title','description','type','price','education_level','tutor_id','created_at']);
    
    if (!$course) {
        return response()->json(['message' => 'Course not found'], 404);
    }
    
    return response()->json($course);
}

// GET /api/courses/recommendations/similar - Get recommended courses based on education level
public function getRecommendations(Request $request)
{
    $user = $request->user();
    
    if (!$user) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    // Get courses matching user's education level that they're not enrolled in
    $enrolledCourseIds = Enrollment::where('user_id', $user->id)
        ->pluck('course_id')
        ->toArray();

    $recommendedCourses = Course::where('education_level', $user->education_level)
        ->whereNotIn('id', $enrolledCourseIds)
        ->where('tutor_id', '!=', $user->id) // Don't recommend own courses for tutors
        ->latest()
        ->limit(10)
        ->get(['id', 'title', 'description', 'type', 'price', 'education_level', 'tutor_id', 'created_at']);

    return response()->json([
        'message' => 'Recommended courses retrieved successfully',
        'data' => $recommendedCourses,
        'total' => $recommendedCourses->count()
    ]);
}
}