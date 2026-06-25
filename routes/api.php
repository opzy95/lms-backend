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
use App\Http\Controllers\Api\CourseForumController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Test route
Route::get('/test', function () {
    return response()->json(['message' => 'API is working!']);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

Route::get('/courses', [CourseController::class, 'index']);
Route::get('/courses/{id}', [CourseController::class, 'showPublic']);

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/tutors/pending', [AdminController::class, 'pendingTutors']);
    Route::post('/approve-tutors/{id}', [AdminController::class, 'approveTutor']);
    Route::post('/reject-tutor/{id}', [AdminController::class, 'rejectTutor']);
    Route::get('/users', [AdminController::class, 'allUsers']);
    Route::get('/courses', [AdminController::class, 'allCourses']);
    Route::post('/publish-course/{id}', [AdminController::class, 'publishCourse']);
});

Route::middleware(['auth:sanctum', 'role:tutor.admin', 'approved'])->prefix('tutor')->group(function () {
    Route::post('/courses', [CourseController::class, 'store']);
    Route::get('/my-courses', [CourseController::class, 'myCourses']);
    Route::put('/courses/{id}', [CourseController::class, 'update']);
    Route::delete('/courses/{id}', [CourseController::class, 'destroy']);
    Route::get('/courses/{id}', [CourseController::class, 'show']);

    Route::post('/courses/{course}/lessons', [LessonController::class, 'store']);
    Route::get('/courses/{course}/lessons', [LessonController::class, 'index']);
    Route::put('/lessons/{lesson}', [LessonController::class, 'update']);
    Route::delete('/lessons/{lesson}', [LessonController::class, 'destroy']);
     Route::post('/lessons/{lesson_id}/quizzes', [QuizController::class, 'store']);
     Route::put('/lessons/{lesson_id}/quizzes', [QuizController::class, 'update']);
    Route::post('/quiz-answers/{answer_id}/grade', [QuizController::class, 'gradeAnswer']);
});

Route::middleware(['auth:sanctum', 'role:student'])->prefix('student')->group(function () {
    Route::post('/enroll/{course_id}', [EnrollmentController::class, 'enroll']);
    Route::get('/lessons/{lesson}/download', [LessonController::class, 'download']);
    Route::get('/dashboard', [StudentController::class, 'dashboard']);
    Route::get('/courses/{course_id}/lessons', [StudentController::class, 'courseLessons']);
    Route::post('/quizzes/{quiz_id}/start', [StudentController::class, 'startQuiz']);
    Route::post('/quiz-attempts/{attempt_id}/submit', [StudentController::class, 'submitQuiz']);
    Route::get('/quiz-attempts/{attempt_id}', [StudentController::class, 'viewAttempt']);
});

Route::middleware('auth:sanctum')->group(function(){
    Route::get('/courses/{course_id}/forum', [CourseForumController::class, 'index']);
    Route::post('/courses/{course_id}/forum/ask', [CourseForumController::class, 'ask']);
    Route::post('/forum/{thread_id}/reply', [CourseForumController::class, 'reply']);
});

Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
