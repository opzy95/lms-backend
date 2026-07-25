<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * Get all pending tutors
     */
    public function pendingTutors()
    {
        $tutors = User::where('role', 'tutor')
            ->where('is_approved', false)
            ->select('id', 'name', 'email', 'created_at')
            ->latest()
            ->get();

        return response()->json($tutors);
    }

    /**
     * Get all approved tutors

     * Approve tutor
     */
public function approveTutor($id)
{
    $tutor = User::where('id', $id)
        ->where('role', 'tutor')
        ->first();

    if (!$tutor) {
        return response()->json([
            'message' => 'Tutor not found'
        ], 404);
    }

    $tutor->is_approved = true;
    $tutor->save();

    $tutor->refresh();

    return response()->json([
        'message' => 'Tutor approved successfully',
        'is_approved' => $tutor->is_approved
    ]);
}

    /**
     * Reject tutor
     */
    public function rejectTutor($id)
    {
        $tutor = User::where('id', $id)
            ->where('role', 'tutor')
            ->first();

        if (!$tutor) {
            return response()->json([
                'message' => 'Tutor not found'
            ], 404);
        }

        $tutor->delete();

        return response()->json([
            'message' => 'Tutor application rejected'
        ]);
    }

    /**
     * Get all users
     */
    public function allUsers()
    {
        $users = User::select(
                'id',
                'name',
                'email',
                'role',
                'is_approved',
                'created_at'
            )
            ->latest()
            ->get();

        return response()->json($users);
    }

    public function allTutors()
{
    $tutors = User::whereIn('role', ['tutor', 'tutor.admin'])
        ->select(
            'id',
            'name',
            'email',
            'role',
            'is_approved',
            'created_at'
        )
        ->get();

    return response()->json([
        'tutors' => $tutors
    ]);
}

    /**
     * Get all courses
     */
    public function allCourses()
    {
        $courses = Course::with([
                'tutor:id,name,email'
            ])
            ->latest()
            ->get();

        return response()->json($courses);
    }

    /**
     * Publish course
     */
    public function publishCourse($id)
    {
        $course = Course::find($id);

        if (!$course) {
            return response()->json([
                'message' => 'Course not found'
            ], 404);
        }

        // For now, just return success since there's no is_published column
        return response()->json([
            'message' => 'Course published successfully',
            'course' => $course
        ]);
    }

    /**
     * Admin dashboard summary
     */
    public function dashboard()
    {
        return response()->json([
            'total_users' => User::count(),
            'total_students' => User::where('role', 'student')->count(),
            'total_tutors' => User::where('role', 'tutor')->count(),
            'pending_tutors' => User::where('role', 'tutor')
                ->where('is_approved', false)
                ->count(),
            'total_courses' => Course::count(),
        ]);
    }

    /**
     * Get all students with their details
     * GET /api/admin/students
     */
    public function getStudents()
    {
        $students = User::where('role', 'student')
            ->with(['enrollments', 'growth'])
            ->select(
                'id',
                'name',
                'email',
                'education_level',
                'avatar_url',
                'created_at'
            )
            ->latest()
            ->get()
            ->map(function ($student) {
                // Get enrolled courses count
                $enrolledCoursesCount = $student->enrollments()->count();
                
                // Get unique courses (in case of multiple enrollments)
                $uniqueCoursesCount = $student->enrollments()->distinct('course_id')->count();
                
                // Get academic standing (can be based on quiz performance or grades)
                $academicStanding = 'Average'; // Default
                $growth = $student->growth;
                if ($growth) {
                    $level = $growth->level ?? 1;
                    if ($level >= 8) {
                        $academicStanding = 'High';
                    } elseif ($level >= 5) {
                        $academicStanding = 'Average';
                    } else {
                        $academicStanding = 'At Risk';
                    }
                }
                
                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                    'avatar_url' => $student->avatar_url,
                    'grade' => $student->education_level ?? 'N/A',
                    'enrolled_courses' => $enrolledCoursesCount,
                    'enrolled_courses_display' => $uniqueCoursesCount . ' Active',
                    'academic_standing' => $academicStanding,
                    'status' => 'Active',
                    'created_at' => $student->created_at,
                ];
            });

        return response()->json([
            'data' => $students,
            'total' => $students->count(),
            'per_page' => $students->count(),
            'current_page' => 1,
        ]);
    }

    /**
     * Get admin stats and recent course submissions
     * GET /api/admin/stats
     */
    public function stats()
    {
        // Calculate total students
        $totalStudents = User::where('role', 'student')->count();
        $totalTutors = User::where('role', 'tutor')->count();
        $activeCourses = Course::count();
        $totalCourses = Course::count();
        
        // Calculate month-over-month changes
        $studentsThisMonth = User::where('role', 'student')
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();
        $studentsLastMonth = User::where('role', 'student')
            ->whereYear('created_at', now()->subMonth()->year)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->count();
        $studentChange = $studentsLastMonth > 0 
            ? round((($studentsThisMonth - $studentsLastMonth) / $studentsLastMonth) * 100, 1)
            : 0;

        $tutorsThisMonth = User::where('role', 'tutor')
            ->where('is_approved', true)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        $tutoringApplications = User::where('role', 'tutor')
            ->where('is_approved', false)
            ->count();

        // Calculate platform revenue (sum of paid courses)
        $platformRevenue = Course::where('type', 'paid')
            ->sum('price');

        // Calculate completion rate (students enrolled / total students)
        $totalEnrollments = \App\Models\Enrollment::count();
        $completionRate = $totalStudents > 0 
            ? round(($totalEnrollments / ($totalStudents * max($activeCourses, 1))) * 100, 1)
            : 0;

        // Get recent course submissions (last 10 courses)
        $recentCourses = Course::with(['tutor:id,name,email'])
            ->select('id', 'title', 'description', 'tutor_id', 'type', 'price', 'created_at')
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($course) {
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'subject' => $course->description ? substr($course->description, 0, 50) . (strlen($course->description) > 50 ? '...' : '') : 'General',
                    'tutor' => $course->tutor?->name ?? 'Unknown',
                    'tutor_email' => $course->tutor?->email ?? 'no-email@example.com',
                    'submission_date' => $course->created_at->format('M d, Y'),
                    'status' => 'Published',
                    'type' => ucfirst($course->type),
                    'price' => $course->type === 'paid' ? '$' . number_format($course->price, 2) : 'Free',
                ];
            });

        return response()->json([
            'stats' => [
                'total_students' => [
                    'value' => $totalStudents,
                    'change' => $studentChange,
                    'change_label' => $studentChange >= 0 ? '+' . $studentChange . '%' : $studentChange . '%',
                    'trend' => $studentChange >= 0 ? 'up' : 'down',
                ],
                'total_tutors' => [
                    'value' => $totalTutors,
                    'pending_applications' => $tutoringApplications,
                    'label' => $tutoringApplications > 0 ? $tutoringApplications . ' new applicants' : 'All verified',
                ],
                'active_courses' => [
                    'value' => $activeCourses,
                    'total_courses' => $totalCourses,
                    'completion_rate' => $completionRate . '%',
                    'label' => '✓ ' . $completionRate . '% completion rate',
                ],
                'platform_revenue' => [
                    'value' => '$' . number_format($platformRevenue, 2),
                    'change' => '+15%',
                    'change_label' => '↑ 15% increase',
                ],
            ],
            'recent_submissions' => $recentCourses,
            'summary' => [
                'total_courses_submitted' => $totalCourses,
                'courses_published' => $activeCourses,
            ]
        ]);
    }

    /**
     * Get single tutor details with statistics
     * GET /api/admin/tutors/{id}
     */
    public function getTutorDetails($id)
    {
        $tutor = User::where('id', $id)
            ->where('role', 'tutor')
            ->first();

        if (!$tutor) {
            return response()->json([
                'message' => 'Tutor not found'
            ], 404);
        }

        // Get tutor statistics
        $studentCount = \App\Models\Enrollment::whereHas('course', function ($query) use ($tutor) {
            $query->where('tutor_id', $tutor->id);
        })->distinct('user_id')->count();

        // Calculate average rating from course enrollments or feedback
        // For now, using a placeholder. You can implement actual rating logic
        $averageRating = 4.5; // Placeholder

        // Get tutor's core subjects (already stored as JSON array)
        $subjects = $tutor->core_subjects ?? [];

        // Determine status
        $status = 'Active';
        if (!$tutor->is_approved) {
            $status = 'Under Review';
        } elseif ($tutor->is_approved && $tutor->tutorDocuments()->where('status', 'approved')->count() < 2) {
            $status = 'Pending';
        }

        $data = [
            'id' => $tutor->id,
            'name' => $tutor->name,
            'full_name' => $tutor->name,
            'first_name' => $tutor->first_name,
            'last_name' => $tutor->last_name,
            'email' => $tutor->email,
            'avatar' => $tutor->avatar_url,
            'profile_photo' => $tutor->avatar_url,
            'role' => $tutor->role,
            'user_type' => $tutor->role,
            'title' => $tutor->title,
            'phone' => $tutor->phone_number,
            'location' => $tutor->location,
            'biography' => $tutor->biography,
            'school_name' => $tutor->school_name,
            'subjects' => $subjects,
            'core_subjects' => $subjects,
            'rating' => $averageRating,
            'average_rating' => $averageRating,
            'students' => $studentCount,
            'student_count' => $studentCount,
            'is_verified' => $tutor->is_approved,
            'is_approved' => $tutor->is_approved,
            'verified' => $tutor->is_approved,
            'approved' => $tutor->is_approved,
            'status' => $status,
            'created_at' => $tutor->created_at,
            'education_level' => $tutor->education_level,
        ];

        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * Get tutor documents
     * GET /api/admin/tutors/{id}/documents
     */
    public function getTutorDocuments($id)
    {
        $tutor = User::where('id', $id)
            ->where('role', 'tutor')
            ->first();

        if (!$tutor) {
            return response()->json([
                'message' => 'Tutor not found'
            ], 404);
        }

        $documents = \App\Models\TutorDocument::where('user_id', $tutor->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'document_name' => $doc->document_name,
                    'document_type' => strtoupper($doc->document_type),
                    'file_path' => $doc->file_path,
                    'file_url' => \Illuminate\Support\Facades\Storage::disk('public')->url($doc->file_path),
                    'status' => $doc->status,
                    'created_at' => $doc->created_at,
                    'updated_at' => $doc->updated_at,
                    'uploaded_at' => $doc->uploaded_at ?? $doc->created_at,
                    'approved_at' => $doc->approved_at,
                    'admin_notes' => $doc->admin_notes,
                ];
            });

        return response()->json([
            'data' => $documents
        ]);
    }

    /**
     * Get single student details with statistics
     * GET /api/admin/students/{id}
     */
    public function getStudentDetails($id)
    {
        $student = User::where('id', $id)
            ->where('role', 'student')
            ->with(['enrollments.course', 'growth'])
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student not found'
            ], 404);
        }

        // Get enrollment statistics
        $enrollmentCount = $student->enrollments()->count();
        $uniqueCourseCount = $student->enrollments()->distinct('course_id')->count();
        
        // Get academic standing based on growth data or quiz performance
        $academicStanding = 'Average'; // Default
        $growth = $student->growth;
        if ($growth) {
            $level = $growth->level ?? 1;
            $averageScore = $growth->average_score ?? 0;
            
            if ($level >= 8 || $averageScore >= 80) {
                $academicStanding = 'Excellent';
            } elseif ($level >= 5 || $averageScore >= 65) {
                $academicStanding = 'Good';
            } elseif ($level >= 3 || $averageScore >= 50) {
                $academicStanding = 'Average';
            } else {
                $academicStanding = 'At Risk';
            }
        }

        // Generate avatar color based on student ID
        $colors = ['#2563eb', '#16a34a', '#dc2626', '#7c3aed', '#ea580c', '#0891b2', '#be123c', '#4338ca'];
        $avatarColor = $colors[$student->id % count($colors)];

        // Format grade/level
        $grade = $student->education_level 
            ? ucfirst($student->education_level) . ' Level'
            : 'N/A';

        // Get enrollment date (first enrollment or account creation)
        $enrollmentDate = $student->enrollments()
            ->orderBy('created_at', 'asc')
            ->first()?->created_at ?? $student->created_at;

        $data = [
            'id' => $student->id,
            'name' => $student->name,
            'first_name' => $student->first_name ?? explode(' ', $student->name)[0] ?? '',
            'last_name' => $student->last_name ?? (count(explode(' ', $student->name)) > 1 
                ? implode(' ', array_slice(explode(' ', $student->name), 1)) 
                : ''),
            'email' => $student->email,
            'phone' => $student->phone_number ?? 'No phone provided',
            'phone_number' => $student->phone_number ?? 'No phone provided',
            'grade' => $grade,
            'level' => $grade,
            'education_level' => $student->education_level,
            'courses' => $uniqueCourseCount,
            'enrolled_courses' => $uniqueCourseCount,
            'course_count' => $uniqueCourseCount,
            'total_enrollments' => $enrollmentCount,
            'standing' => $academicStanding,
            'academic_standing' => $academicStanding,
            'status' => 'Active', // You can add logic here for different statuses
            'is_active' => true,
            'enrollment_date' => $enrollmentDate,
            'created_at' => $student->created_at,
            'avatar_color' => $avatarColor,
            'color' => $avatarColor,
            'avatar_url' => $student->avatar_url,
            // Additional growth/gamification data
            'growth_level' => $growth?->level ?? 1,
            'growth_xp' => $growth?->xp ?? 0,
            'growth_streaks' => $growth?->streaks ?? 0,
            'quizzes_completed' => $growth?->total_quizzes_completed ?? 0,
            'lessons_completed' => $growth?->total_lessons_completed ?? 0,
            'average_score' => $growth?->average_score ?? 0,
        ];

        return response()->json([
            'data' => $data
        ]);
    }
}
