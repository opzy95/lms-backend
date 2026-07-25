<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TutorController;
use App\Http\Controllers\Api\CourseForumController;
use App\Http\Controllers\Api\TutorDocumentController;
use App\Http\Controllers\Api\LiveClassController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/test', function () {
    return response()->json(['message' => 'API is working!']);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/courses', [CourseController::class, 'index']);
Route::get('/courses/{id}', [CourseController::class, 'showPublic']);

Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Course recommendations for authenticated users
    Route::get('/courses/recommendations/similar', [CourseController::class, 'getRecommendations']);

    // Fallback routes for student details (for AdminStudentDetails component)
    Route::middleware('role:admin')->group(function () {
        Route::get('/students/{id}', [AdminController::class, 'getStudentDetails']);
        Route::get('/users/{id}', [AdminController::class, 'getStudentDetails']);
    });

    // Live class routes (general - accessible to all authenticated users)
    Route::get('/courses/{course_id}/live-classes', [LiveClassController::class, 'index']);
    Route::get('/live-classes', [LiveClassController::class, 'studentIndex']);
    Route::get('/live-classes/status/{status}', [LiveClassController::class, 'studentIndexByStatus']);
    Route::get('/live-classes/{id}', [LiveClassController::class, 'studentShow']);
    Route::post('/live-classes/{id}/join', [LiveClassController::class, 'studentJoin']);
    Route::post('/live-classes/{id}/leave', [LiveClassController::class, 'studentLeave']);

    /*
    |--------------------------------------------------------------------------
    | Forum (Authenticated users)
    |--------------------------------------------------------------------------
    */
    Route::get('/courses/{course_id}/forum', [CourseForumController::class, 'index']);
    Route::post('/courses/{course_id}/forum/ask', [CourseForumController::class, 'ask']);
    Route::post('/forum/{thread_id}/reply', [CourseForumController::class, 'reply']);
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'role:admin'])
    ->prefix('admin')
    ->group(function () {

        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/stats', [AdminController::class, 'stats']);

        Route::get('/students', [AdminController::class, 'getStudents']);
        Route::get('/students/{id}', [AdminController::class, 'getStudentDetails']);

        Route::get('/tutors', [AdminController::class, 'allTutors']);
        Route::get('/tutors/{id}', [AdminController::class, 'getTutorDetails']);
        Route::get('/tutors/{id}/documents', [AdminController::class, 'getTutorDocuments']);
        Route::get('/tutors/pending', [AdminController::class, 'pendingTutors']);

        Route::put(
        '/tutors/{id}/approve',
        [AdminController::class, 'approveTutor']
    );

        Route::post('/reject-tutor/{id}', [AdminController::class, 'rejectTutor']);

        Route::get('/users', [AdminController::class, 'allUsers']);
        Route::get('/users/{id}', [AdminController::class, 'getStudentDetails']); // Fallback for students
        Route::get('/courses', [AdminController::class, 'allCourses']);

        Route::post('/publish-course/{id}', [AdminController::class, 'publishCourse']);
    });

/*
|--------------------------------------------------------------------------
| Tutor Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'role:tutor', 'approved'])
    ->prefix('tutor')
    ->group(function () {

        Route::get('/dashboard', [TutorController::class, 'dashboard']);
        Route::put('/dashboard', [TutorController::class, 'updateDashboard']);

        // Tutor Profile Routes
        Route::get('/profile', [TutorController::class, 'getProfile']);
        Route::put('/profile', [TutorController::class, 'updateProfile']);
        Route::post('/upload-avatar', [TutorController::class, 'uploadAvatar']);

        // Tutor profile/courses
        Route::get('/my-courses', [CourseController::class, 'myCourses']);
        Route::post('/courses', [CourseController::class, 'store']);
        Route::get('/courses/{id}', [CourseController::class, 'show']);
        Route::put('/courses/{id}', [CourseController::class, 'update']);
        Route::delete('/courses/{id}', [CourseController::class, 'destroy']);

        // Students
        Route::get('/courses/{course_id}/students', [TutorController::class, 'courseStudents']);
        Route::get('/lessons/{lesson_id}/students', [TutorController::class, 'lessonStudents']);

        // Lessons
        Route::get('/lessons', [LessonController::class, 'allLessons']);
        Route::get('/lessons/stats', [LessonController::class, 'lessonStats']);
        Route::get('/lessons/{lesson}', [LessonController::class, 'show']);
        Route::get('/courses-with-lessons', [CourseController::class, 'coursesWithLessons']);
        Route::post('/courses/{course}/lessons', [LessonController::class, 'store']);
        Route::get('/courses/{course}/lessons', [LessonController::class, 'index']);
        Route::put('/lessons/{lesson}', [LessonController::class, 'update']);
        Route::delete('/lessons/{lesson}', [LessonController::class, 'destroy']);

        // Quizzes
        Route::post('/lessons/{lesson_id}/quizzes', [QuizController::class, 'store']);
        Route::get('/lessons/{lesson_id}/quiz', [QuizController::class, 'getByLesson']);
        Route::get('/lessons/{lesson_id}/quizzes', [QuizController::class, 'getByLesson']); // Alias for convenience
        Route::get('/quizzes/by-lesson/{lesson_id}', [QuizController::class, 'getByLesson']); // Alternative route
        Route::put('/lessons/{lesson_id}/quizzes', [QuizController::class, 'update']);
        Route::post('/quiz-answers/{answer_id}/grade', [QuizController::class, 'gradeAnswer']);

        // Live Classes - Tutor endpoints
        Route::get('/live-classes', [LiveClassController::class, 'tutorIndex']);
        Route::post('/live-classes', [LiveClassController::class, 'tutorStore']);
        Route::put('/live-classes/{id}/start', [LiveClassController::class, 'tutorStart']);
        Route::put('/live-classes/{id}/end', [LiveClassController::class, 'tutorEnd']);
        Route::delete('/live-classes/{id}', [LiveClassController::class, 'tutorDestroy']);
        Route::get('/live-classes/{id}/attendance', [LiveClassController::class, 'tutorAttendance']);

        // Tutor Documents
        Route::post('/documents', [TutorDocumentController::class, 'uploadDocument']);
        Route::get('/documents', [TutorDocumentController::class, 'getDocuments']);
        Route::get('/documents/{id}', [TutorDocumentController::class, 'getDocument']);
        Route::delete('/documents/{id}', [TutorDocumentController::class, 'deleteDocument']);
    });


/*
|--------------------------------------------------------------------------
| Student Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'role:student'])
    ->prefix('student')
    ->group(function () {

        Route::get('/dashboard', [StudentController::class, 'dashboard']);
        Route::get('/dashboard-stats', [StudentController::class, 'dashboardStats']);
        Route::get('/available-courses', [StudentController::class, 'availableCourses']);
        Route::get('/enrolled-courses', [StudentController::class, 'enrolledCourses']);

        // Student Profile Routes
        Route::get('/profile', [StudentController::class, 'getProfile']);
        Route::put('/profile', [StudentController::class, 'updateProfile']);
        Route::post('/upload-avatar', [StudentController::class, 'uploadAvatar']);
        Route::put('/settings', [StudentController::class, 'updateSettings']);

        // Student Goals Routes
        Route::get('/goals', [StudentController::class, 'getGoals']);
        Route::post('/goals', [StudentController::class, 'createGoal']);
        Route::put('/goals/{id}', [StudentController::class, 'updateGoal']);
        Route::delete('/goals/{id}', [StudentController::class, 'deleteGoal']);

        // Student Growth/Gamification Routes
        Route::get('/growth', [StudentController::class, 'getGrowth']);

        Route::post('/enroll/{course_id}', [EnrollmentController::class, 'enroll']);

        Route::get('/courses/{course_id}/lessons', [StudentController::class, 'courseLessons']);
        Route::get('/lessons/{lesson}/download', [LessonController::class, 'download']);

        // Lesson Progress Tracking
        Route::post('/lessons/{lesson_id}/start', [StudentController::class, 'startLesson']);
        Route::post('/lessons/{lesson_id}/mark-read', [StudentController::class, 'markLessonRead']);
        Route::get('/lessons/{lesson_id}/quiz', [QuizController::class, 'getForStudent']);
        Route::post('/lessons/{lesson_id}/complete-quiz', [StudentController::class, 'completeQuiz']);

        // Quiz
        Route::post('/quizzes/{quiz_id}/start', [StudentController::class, 'startQuiz']);
        Route::post('/quiz-attempts/{attempt_id}/submit', [StudentController::class, 'submitQuiz']);
        Route::get('/quiz-attempts/{attempt_id}', [StudentController::class, 'viewAttempt']);
    });