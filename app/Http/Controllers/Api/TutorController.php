<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class TutorController extends Controller
{
    // POST /api/tutor/courses
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

        // Force price=0 for free courses
        $price = $request->type === 'free' ? 0 : $request->price;

        if ($request->type === 'paid' && $price <= 0) {
            return response()->json(['message' => 'Paid courses must have price > 0'], 422);
        }

        $course = Course::create([
            'tutor_id' => $request->user()->id, // auto-fill logged in tutor
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

   

public function tutors()
{
    $tutors = User::where('role', 'tutor')
        ->select('id', 'name', 'email')
        ->get();

    return response()->json($tutors);
}

    // GET /api/tutor/courses - list only this tutor's courses
    public function index(Request $request)
    {
        $courses = Course::where('tutor_id', $request->user()->id)
                         ->latest()
                         ->get();
        return response()->json($courses);
    }

    // PUT /api/tutor/courses/{id} - update course
   public function update(Request $request, $id)
{
    $course = Course::where('id', $id)
        ->where('tutor_id', $request->user()->id)
        ->first();

    if (!$course) {
        return response()->json(['message' => 'Course not found'], 404);
    }

    $validator = Validator::make($request->all(), [
        'title' => 'sometimes|string|max:255',
        'description' => 'sometimes|string',
        'type' => 'sometimes|in:free,paid',
        'price' => 'nullable|numeric|min:0',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $data = $request->only(['title', 'description', 'type', 'price']);
    if (($data['type'] ?? $course->type) === 'free') {
        $data['price'] = 0;
    }

    $course->update($data);

    return response()->json(['message' => 'Course updated', 'course' => $course]);
}

  public function dashboard()
    {
        try {
            $user = auth()->user();

            $response = [
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'phone' => $user->phone_number,
                        'title' => $user->title,
                        'biography' => $user->biography,
                        'location' => $user->location,
                        'avatar_url' => $user->avatar_url ?? null,
                        'is_approved' => $user->is_approved,
                    ],
                    'core_subjects' => $user->core_subjects ?? [],
                    'qualifications' => $this->getQualifications($user),
                ]
            ];

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateDashboard(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:50',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|unique:users,phone_number,' . $user->id,
            'location' => 'nullable|string|max:255',
            'biography' => 'nullable|string|max:1000',
            'core_subjects' => 'nullable|array',
            'core_subjects.*' => 'string|max:100',
            'avatar_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->only([
                'title',
                'first_name',
                'last_name',
                'location',
                'biography',
                'core_subjects',
                'avatar_url'
            ]);

            // Handle phone field mapping
            if ($request->has('phone')) {
                $data['phone_number'] = $request->phone;
            }

            // Remove null values to only update provided fields
            $data = array_filter($data, function ($value) {
                return $value !== null;
            });

            $user->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Dashboard updated successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'phone' => $user->phone_number,
                        'title' => $user->title,
                        'biography' => $user->biography,
                        'location' => $user->location,
                        'avatar_url' => $user->avatar_url ?? null,
                        'is_approved' => $user->is_approved,
                    ],
                    'core_subjects' => $user->core_subjects ?? [],
                    'qualifications' => $this->getQualifications($user),
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

     public function courseStudents($course_id)
    {
        $course = Course::where('id', $course_id)
            ->where('tutor_id', auth()->id())->first();
        if(!$course) return response()->json(['message' => 'Unauthorized'], 403);

        $students = Enrollment::with([
            'user:id,name,email',
            'user.quizAttempts' => function($q) use ($course_id) {
                $q->whereHas('quiz.lesson', fn($l) => $l->where('course_id', $course_id));
            }
        ])
        ->where('course_id', $course_id)
        ->get()
        ->map(function($enroll) {
            return [
                'user_id' => $enroll->user_id,
                'name' => $enroll->user->name,
                'email' => $enroll->user->email,
                'enrolled_at' => $enroll->enrolled_at,
                'quizzes_submitted' => $enroll->user->quizAttempts->count(),
                'last_activity' => $enroll->user->quizAttempts->max('submitted_at')
            ];
        });

        return response()->json([
            'course_id' => $course_id,
            'course_title' => $course->title,
            'total_enrolled' => $students->count(),
            'students' => $students
        ]);
    }

      public function lessonStudents($lesson_id)
    {
        $lesson = Lesson::where('id', $lesson_id)
    ->whereHas('course', fn($q) => $q->where('tutor_id', auth()->id()))
    ->first();
        if(!$lesson) return response()->json(['message' => 'Unauthorized'], 403);

        $quiz = Quiz::where('lesson_id', $lesson_id)->first();

        $enrolled = Enrollment::with('user:id,name,email')
            ->where('course_id', $lesson->course_id)->get();

        $submittedIds = $quiz ?
            QuizAttempt::where('quiz_id', $quiz->id)->pluck('student_id') :
            collect();

        $students = $enrolled->map(function($e) use ($submittedIds) {
            return [
                'user_id' => $e->user_id,
                'name' => $e->user->name,
                'email' => $e->user->email,
                'status' => $submittedIds->contains($e->user_id) ? 'submitted' : 'not_started'
            ];
        });

        return response()->json([
            'lesson_id' => $lesson_id,
            'lesson_title' => $lesson->title,
            'total_enrolled' => $students->count(),
            'submitted_count' => $submittedIds->count(),
            'students' => $students
        ]);
    }

    /**
     * GET /api/tutor/profile
     * Get tutor profile with all information
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone_number,
                    'title' => $user->title,
                    'biography' => $user->biography,
                    'location' => $user->location,
                    'avatar_url' => $user->avatar_url ?? null,
                    'is_approved' => $user->is_approved,
                ],
                'core_subjects' => $user->core_subjects ?? [],
                'qualifications' => $this->getQualifications($user),
            ]
        ], 200);
    }

    /**
     * PUT /api/tutor/profile
     * Update tutor profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:50',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|unique:users,phone_number,' . $user->id,
            'location' => 'nullable|string|max:255',
            'biography' => 'nullable|string|max:1000',
            'school_name' => 'nullable|string|max:255',
            'core_subjects' => 'nullable|array',
            'core_subjects.*' => 'string|max:100',
            'qualifications' => 'nullable|array',
            'qualifications.*.degree' => 'string|max:255',
            'qualifications.*.institution' => 'string|max:255',
            'qualifications.*.year' => 'integer|min:1950|max:' . date('Y'),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Map phone to phone_number for database
            $data = $request->only([
                'title',
                'first_name',
                'last_name',
                'location',
                'biography',
                'school_name',
                'core_subjects'
            ]);

            // Handle phone field mapping
            if ($request->has('phone')) {
                $data['phone_number'] = $request->phone;
            }

            // Remove null values to only update provided fields
            $data = array_filter($data, function ($value) {
                return $value !== null;
            });

            $user->update($data);

            // Store qualifications (you may want to create a separate table for this)
            // For now, we'll return them as provided
            $qualifications = $request->qualifications ?? [];

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'phone' => $user->phone_number,
                        'title' => $user->title,
                        'biography' => $user->biography,
                        'location' => $user->location,
                        'avatar_url' => $user->avatar_url ?? null,
                        'is_approved' => $user->is_approved,
                    ],
                    'core_subjects' => $user->core_subjects ?? [],
                    'qualifications' => $qualifications,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload tutor profile picture/avatar
     * POST /api/tutor/upload-avatar
     */
    public function uploadAvatar(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('avatar');
            $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();

            // Store file in avatars directory
            $filePath = $file->storeAs('avatars', $filename, 'public');

            // Delete old avatar if it exists
            if ($user->avatar_url) {
                $oldPath = str_replace(config('app.url') . '/storage/', '', $user->avatar_url);
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            // Update user avatar URL
            $avatarUrl = Storage::disk('public')->url($filePath);
            $user->update(['avatar_url' => $avatarUrl]);

            return response()->json([
                'message' => 'Avatar uploaded successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to upload avatar',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper function to get qualifications
     * Can be extended to fetch from a separate qualifications table
     */
    private function getQualifications($user)
    {
        // This is a placeholder - you may want to create a tutor_qualifications table
        // For now, return empty array
        return [];
    }
}
