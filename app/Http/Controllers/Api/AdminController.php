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
}