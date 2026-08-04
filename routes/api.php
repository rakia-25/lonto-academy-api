<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExerciseSubmissionController;
use App\Http\Controllers\Api\QuizAttemptController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\CourseController as AdminCourseController;
use App\Http\Controllers\Api\Admin\ExamController as AdminExamController;
use App\Http\Controllers\Api\Admin\ExerciseSubmissionController as AdminExerciseSubmissionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\StatsController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LessonProgressController;
use App\Http\Controllers\Api\PlatformSettingController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me',              [AuthController::class, 'me']);
    Route::put('/me',              [AuthController::class, 'updateProfile']);
    Route::put('/me/password',     [AuthController::class, 'updatePassword']);
    Route::post('/logout',         [AuthController::class, 'logout']);
    Route::get('/my-learning',     [CourseController::class, 'myLearning']);
    Route::get('/dashboard',       [DashboardController::class, 'index']);

    // Inscription / achat
    Route::post('/courses/{slug}/checkout', [EnrollmentController::class, 'checkout']);

    // Progression des leçons
    Route::put('/lessons/{lesson}/progress',      [LessonProgressController::class, 'update']);
    Route::get('/courses/{course}/progress',      [LessonProgressController::class, 'courseProgress']);

    // Quiz & exercices
    Route::post('/quizzes/{quiz}/attempt',        [QuizAttemptController::class, 'store']);
    Route::get('/quizzes/{quiz}/attempt',         [QuizAttemptController::class, 'latest']);
    Route::get('/quizzes/{quiz}/attempts',        [QuizAttemptController::class, 'history']);
    Route::post('/exercises/{exercise}/submit',   [ExerciseSubmissionController::class, 'store']);
    Route::get('/exercises/{exercise}/submission',[ExerciseSubmissionController::class, 'latest']);
    Route::get('/exercises/{exercise}/submission/download', [ExerciseSubmissionController::class, 'download']);
    Route::get('/exercises/{exercise}/submission/preview', [ExerciseSubmissionController::class, 'preview']);
    Route::delete('/exercises/{exercise}/submission', [ExerciseSubmissionController::class, 'destroy']);

    // Examen final
    Route::get('/courses/{course}/exam',              [ExamController::class, 'show']);
    Route::post('/exams/{exam}/start',                [ExamController::class, 'start']);
    Route::patch('/exam-attempts/{attempt}/answers',  [ExamController::class, 'saveAnswers']);
    Route::post('/exam-attempts/{attempt}/violation', [ExamController::class, 'reportViolation']);
    Route::post('/exam-attempts/{attempt}/upload',    [ExamController::class, 'uploadAnswerFile']);
    Route::post('/exam-attempts/{attempt}/submit',    [ExamController::class, 'submit']);
    Route::get('/exam-attempts/{attempt}/result',     [ExamController::class, 'result']);
    Route::get('/exam-attempts/{attempt}/files/{questionId}', [ExamController::class, 'downloadAnswerFile']);

    // Notifications
    Route::get('/notifications',                  [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::get('/notifications/unread-count',     [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{notification}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead']);
    Route::post('/notifications/mark-all-read',   [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);

    // Certificats
    Route::get('/certificates/{code}/download',   [CertificateController::class, 'download']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Stats
    Route::get('/stats', [StatsController::class, 'index']);

    // Paiements (lecture / export — sans gateway)
    Route::get('/payments',        [AdminPaymentController::class, 'index']);
    Route::get('/payments/export', [AdminPaymentController::class, 'export']);

    // Cours
    Route::get('/courses',                                    [AdminCourseController::class, 'index']);
    Route::post('/courses',                                   [AdminCourseController::class, 'store']);
    Route::get('/courses/{course}',                           [AdminCourseController::class, 'show']);
    Route::put('/courses/{course}',                           [AdminCourseController::class, 'update']);
    Route::delete('/courses/{course}',                        [AdminCourseController::class, 'destroy']);
    Route::patch('/courses/{course}/toggle-publish',          [AdminCourseController::class, 'togglePublish']);
    Route::post('/courses/{course}/thumbnail',                [AdminCourseController::class, 'uploadThumbnail']);
    Route::post('/courses/{course}/chapters',                 [AdminCourseController::class, 'addChapter']);
    Route::put('/chapters/{chapter}',                         [AdminCourseController::class, 'updateChapter']);
    Route::delete('/chapters/{chapter}',                      [AdminCourseController::class, 'destroyChapter']);
    Route::post('/chapters/{chapter}/video',                  [AdminCourseController::class, 'addChapterVideo']);
    Route::post('/courses/{course}/chapters/{chapter}/lessons', [AdminCourseController::class, 'addLesson']);
    Route::put('/lessons/{lesson}',                           [AdminCourseController::class, 'updateLesson']);
    Route::delete('/lessons/{lesson}',                        [AdminCourseController::class, 'destroyLesson']);
    Route::post('/lessons/{lesson}/resources',                [AdminCourseController::class, 'addLessonResource']);
    Route::post('/chapters/{chapter}/exercises',              [AdminCourseController::class, 'storeExercise']);
    Route::put('/exercises/{exercise}',                       [AdminCourseController::class, 'updateExercise']);
    Route::post('/exercises/{exercise}',                      [AdminCourseController::class, 'updateExercise']); // multipart
    Route::delete('/exercises/{exercise}',                    [AdminCourseController::class, 'destroyExercise']);
    Route::post('/chapters/{chapter}/quiz',                   [AdminCourseController::class, 'storeQuiz']);
    Route::delete('/quizzes/{quiz}',                          [AdminCourseController::class, 'destroyQuiz']);

    // Utilisateurs
    Route::get('/users',                      [AdminUserController::class, 'index']);
    Route::get('/users/{user}',               [AdminUserController::class, 'show']);
    Route::patch('/users/{user}/toggle-block',[AdminUserController::class, 'toggleBlock']);

    // Examens
    Route::get('/courses/{course}/exam',              [AdminExamController::class, 'show']);
    Route::post('/courses/{course}/exam',             [AdminExamController::class, 'store']);
    Route::put('/exams/{exam}',                       [AdminExamController::class, 'update']);
    Route::delete('/exams/{exam}',                    [AdminExamController::class, 'destroy']);
    Route::patch('/exams/{exam}/toggle-publish',      [AdminExamController::class, 'togglePublish']);
    Route::put('/exams/{exam}/questions',             [AdminExamController::class, 'syncQuestions']);
    Route::get('/exams/{exam}/results',               [AdminExamController::class, 'results']);
    Route::get('/certificate-templates',              [AdminExamController::class, 'certificateTemplates']);
    Route::post('/exams/{exam}/certificate-preview',  [AdminExamController::class, 'previewCertificate']);
    Route::get('/exam-attempts/{attempt}',            [AdminExamController::class, 'attemptDetail']);
    Route::patch('/exam-attempts/{attempt}/grade',    [AdminExamController::class, 'gradeAttempt']);
    Route::get('/exam-attempts/{attempt}/files/{questionId}', [AdminExamController::class, 'downloadAnswerFile']);

    // Soumissions d'exercices (consultation & correction)
    Route::get('/exercise-submissions',                              [AdminExerciseSubmissionController::class, 'index']);
    Route::get('/exercise-submissions/{submission}',                 [AdminExerciseSubmissionController::class, 'show']);
    Route::patch('/exercise-submissions/{submission}/correct',       [AdminExerciseSubmissionController::class, 'correct']);
    Route::get('/exercise-submissions/{submission}/download',        [AdminExerciseSubmissionController::class, 'download']);
    Route::get('/exercise-submissions/{submission}/preview',         [AdminExerciseSubmissionController::class, 'preview']);

    // Paramètres plateforme
    Route::put('/settings', [PlatformSettingController::class, 'update']);
    Route::post('/settings/site-logo', [PlatformSettingController::class, 'uploadSiteLogo']);
    Route::delete('/settings/site-logo', [PlatformSettingController::class, 'deleteSiteLogo']);
    Route::post('/settings/certificate-logo', [PlatformSettingController::class, 'uploadCertificateLogo']);
    Route::delete('/settings/certificate-logo', [PlatformSettingController::class, 'deleteCertificateLogo']);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);
// Routes publiques
Route::get('/settings',         [PlatformSettingController::class, 'show']);
Route::get('/courses',          [CourseController::class, 'index']);
Route::get('/courses/{slug}',   [CourseController::class, 'show']);
Route::get('/certificates/{code}/verify', [CertificateController::class, 'verify']);
Route::get('/lesson-resources/{resource}/download', [AdminCourseController::class, 'downloadLessonResource']);
Route::get('/exercises/{exercise}/download', [AdminCourseController::class, 'downloadExerciseFile']);
