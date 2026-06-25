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

        $course = Course::create([
            'tutor_id' => $request->user()->id,
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type,
            'price' => $price
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
public function index()
{
    $courses = Course::latest()->get(['id','title','description','type','price','tutor_id','created_at']);
    return response()->json($courses);
}

// GET /api/courses/{id} - Public single course
public function showPublic($id)
{
    $course = Course::find($id, ['id','title','description','type','price','tutor_id','created_at']);
    
    if (!$course) {
        return response()->json(['message' => 'Course not found'], 404);
    }
    
    return response()->json($course);
}
}