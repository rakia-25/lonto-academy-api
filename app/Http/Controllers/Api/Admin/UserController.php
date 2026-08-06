<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\ExamAttempt;
use App\Models\ExerciseSubmission;
use App\Models\LessonProgress;
use App\Models\Payment;
use App\Models\PersonalAccessToken;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Audit;
use App\Support\CourseProgress;
use App\Support\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
            'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'course_id' => 'nullable|integer|exists:courses,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $status = $validated['status'] ?? 'all';
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = User::query()
            ->where('role', 'learner')
            ->withCount('enrollments')
            ->orderByDesc('created_at');

        if ($status === 'active') {
            $query->whereNull('blocked_at');
        } elseif ($status === 'inactive') {
            $query->whereNotNull('blocked_at');
        }

        if (! empty($validated['course_id'])) {
            $courseId = (int) $validated['course_id'];
            $query->whereHas('enrollments', fn ($q) => $q->where('course_id', $courseId));
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $idleMinutes = max(1, (int) config('session_security.idle_timeout_minutes', 20));
        $onlineThreshold = now()->subMinutes($idleMinutes);

        $users = $query->paginate($perPage);
        $userIds = collect($users->items())->pluck('id');

        $onlineIds = PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $userIds)
            ->where(function ($q) use ($onlineThreshold) {
                $q->where('last_activity_at', '>=', $onlineThreshold)
                    ->orWhere(function ($q2) use ($onlineThreshold) {
                        $q2->whereNull('last_activity_at')
                            ->where('created_at', '>=', $onlineThreshold);
                    });
            })
            ->pluck('tokenable_id')
            ->unique()
            ->all();

        $onlineLookup = array_fill_keys($onlineIds, true);

        $data = collect($users->items())->map(function (User $user) use ($onlineLookup) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'blocked_at' => $user->blocked_at,
                'created_at' => $user->created_at,
                'enrollments_count' => (int) $user->enrollments_count,
                'is_online' => isset($onlineLookup[$user->id]),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
            'summary' => [
                'total' => User::where('role', 'learner')->count(),
                'active' => User::where('role', 'learner')->whereNull('blocked_at')->count(),
                'inactive' => User::where('role', 'learner')->whereNotNull('blocked_at')->count(),
            ],
            'filters' => [
                'courses' => Course::query()
                    ->orderBy('title')
                    ->get(['id', 'title'])
                    ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title]),
            ],
        ]);
    }

    public function show(User $user)
    {
        if ($user->role !== 'learner') {
            return response()->json(['message' => 'Cet utilisateur n\'est pas un apprenant.'], 404);
        }

        $user->load([
            'enrollments' => fn ($q) => $q->orderByDesc('created_at'),
            'enrollments.course:id,title,slug,price,level,is_published',
        ]);

        $courses = [];
        $completedCourses = 0;
        $activityDates = collect();
        $quizPassed = 0;
        $quizFailed = 0;

        foreach ($user->enrollments as $enrollment) {
            $course = $enrollment->course;
            if (! $course) {
                continue;
            }

            $course->load(['chapters.lessons', 'chapters.quiz', 'chapters.exercise']);
            $stats = CourseProgress::stats($user, $course);
            $eligibility = CourseProgress::examEligibility($user, $course);

            if ($stats['is_complete']) {
                $completedCourses++;
            }

            $lessonIds = $course->chapters->flatMap(fn ($c) => $c->lessons->pluck('id'));
            $lessonProgress = $lessonIds->isNotEmpty()
                ? LessonProgress::where('user_id', $user->id)
                    ->whereIn('lesson_id', $lessonIds)
                    ->get()
                : collect();

            $lastLessonAt = $lessonProgress->max('updated_at');
            if ($lastLessonAt) {
                $activityDates->push($lastLessonAt);
            }

            $quizzes = [];
            foreach ($course->chapters as $chapter) {
                if (! $chapter->quiz) {
                    continue;
                }
                $attempts = QuizAttempt::where('user_id', $user->id)
                    ->where('quiz_id', $chapter->quiz->id)
                    ->orderByDesc('created_at')
                    ->get();

                if ($attempts->isNotEmpty()) {
                    $activityDates->push($attempts->max('created_at'));
                }

                $best = $attempts->sortByDesc('score')->first();
                $passed = $attempts->contains(fn ($a) => $a->passed);
                if ($attempts->isNotEmpty()) {
                    if ($passed) {
                        $quizPassed++;
                    } else {
                        $quizFailed++;
                    }
                }

                $quizzes[] = [
                    'quiz_id' => $chapter->quiz->id,
                    'chapter_title' => $chapter->title,
                    'title' => $chapter->quiz->title,
                    'pass_score' => $chapter->quiz->pass_score,
                    'passed' => $passed,
                    'best_score' => $best?->score,
                    'attempts_count' => $attempts->count(),
                    'attempts' => $attempts->take(10)->map(fn ($a) => [
                        'id' => $a->id,
                        'score' => $a->score,
                        'passed' => (bool) $a->passed,
                        'created_at' => $a->created_at,
                    ])->values(),
                ];
            }

            $exercises = [];
            foreach ($course->chapters as $chapter) {
                if (! $chapter->exercise) {
                    continue;
                }
                $submission = ExerciseSubmission::where('user_id', $user->id)
                    ->where('exercise_id', $chapter->exercise->id)
                    ->first();

                if ($submission?->submitted_at) {
                    $activityDates->push($submission->submitted_at);
                }
                if ($submission?->corrected_at) {
                    $activityDates->push($submission->corrected_at);
                }

                $exercises[] = [
                    'exercise_id' => $chapter->exercise->id,
                    'chapter_title' => $chapter->title,
                    'title' => $chapter->exercise->title,
                    'submitted' => (bool) $submission?->submitted_at,
                    'submitted_at' => $submission?->submitted_at,
                    'status' => $submission?->status ?? null,
                    'score' => $submission?->score,
                    'feedback' => $submission?->feedback,
                    'corrected_at' => $submission?->corrected_at,
                    'file_path' => $submission?->file_path,
                ];
            }

            $examAttempts = ExamAttempt::where('user_id', $user->id)
                ->whereHas('exam', fn ($q) => $q->where('course_id', $course->id))
                ->with('exam:id,course_id,title,pass_score')
                ->orderByDesc('created_at')
                ->get();

            if ($examAttempts->isNotEmpty()) {
                $activityDates->push($examAttempts->max('submitted_at') ?: $examAttempts->max('started_at'));
            }

            $closedAttempts = $examAttempts->filter(fn ($a) => $a->status !== 'in_progress');
            $bestExamAttempt = $closedAttempts->sortByDesc('score')->first();
            $examPassed = $closedAttempts->contains(fn ($a) => $a->passed);
            $examPendingReview = $closedAttempts->contains(fn ($a) => $a->status === 'pending_review');

            $activityDates->push($enrollment->created_at);

            $courses[] = [
                'enrollment' => [
                    'id' => $enrollment->id,
                    'type' => $enrollment->type,
                    'created_at' => $enrollment->created_at,
                    'expires_at' => $enrollment->expires_at,
                ],
                'course' => [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                    'level' => $course->level,
                    'price' => $course->price,
                ],
                'stats' => $stats,
                'exam_eligibility' => $eligibility,
                'exam_summary' => [
                    'title' => $bestExamAttempt?->exam?->title
                        ?? $examAttempts->first()?->exam?->title,
                    'pass_score' => $bestExamAttempt?->exam?->pass_score
                        ?? $examAttempts->first()?->exam?->pass_score,
                    'best_score' => $bestExamAttempt?->score,
                    'passed' => $examPassed,
                    'pending_review' => $examPendingReview,
                    'attempts_count' => $closedAttempts->count(),
                ],
                'quizzes' => $quizzes,
                'exercises' => $exercises,
                'exam_attempts' => $examAttempts->map(fn ($a) => [
                    'id' => $a->id,
                    'exam_id' => $a->exam_id,
                    'exam_title' => $a->exam?->title,
                    'pass_score' => $a->exam?->pass_score,
                    'score' => $a->score,
                    'passed' => (bool) $a->passed,
                    'status' => $a->status,
                    'started_at' => $a->started_at,
                    'submitted_at' => $a->submitted_at,
                ])->values(),
                'lessons' => [
                    'total' => $stats['total_lessons'],
                    'completed' => $stats['completed_lessons'],
                    'last_at' => $lastLessonAt,
                ],
            ];
        }

        $certificates = Certificate::where('user_id', $user->id)
            ->with('course:id,title,slug')
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type ?? 'certificat',
                'course_title' => $c->course?->title,
                'course_slug' => $c->course?->slug,
                'issued_at' => $c->issued_at,
                'verification_code' => $c->verification_code,
            ]);

        $payments = Payment::where('user_id', $user->id)
            ->with('course:id,title')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'course_title' => $p->course?->title,
                'amount' => $p->amount,
                'method' => $p->method,
                'status' => $p->status,
                'reference' => $p->reference,
                'created_at' => $p->created_at,
            ]);

        $allQuizAttempts = QuizAttempt::where('user_id', $user->id)->count();
        $allExerciseSubs = ExerciseSubmission::where('user_id', $user->id)
            ->whereNotNull('submitted_at')
            ->count();
        $allExamAttempts = ExamAttempt::where('user_id', $user->id)->count();

        $loginHistory = ActivityLog::query()
            ->where('user_id', $user->id)
            ->whereIn('action', ['auth.login', 'auth.logout', 'auth.password_reset', 'auth.register'])
            ->latest()
            ->take(40)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
            ]);

        $lastLogin = ActivityLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'auth.login')
            ->latest()
            ->value('created_at');

        $lastActivity = $activityDates->filter()->sortDesc()->first();

        $idleMinutes = max(1, (int) config('session_security.idle_timeout_minutes', 20));
        $onlineThreshold = now()->subMinutes($idleMinutes);
        $isOnline = PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->where(function ($q) use ($onlineThreshold) {
                $q->where('last_activity_at', '>=', $onlineThreshold)
                    ->orWhere(function ($q2) use ($onlineThreshold) {
                        $q2->whereNull('last_activity_at')
                            ->where('created_at', '>=', $onlineThreshold);
                    });
            })
            ->exists();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'blocked_at' => $user->blocked_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'last_login_at' => $lastLogin,
                'is_online' => $isOnline,
            ],
            'summary' => [
                'enrollments_count' => $user->enrollments->count(),
                'courses_completed' => $completedCourses,
                'quiz_attempts' => $allQuizAttempts,
                'quiz_passed' => $quizPassed,
                'quiz_failed' => $quizFailed,
                'exercises_submitted' => $allExerciseSubs,
                'exam_attempts' => $allExamAttempts,
                'certificates_count' => $certificates->count(),
                'payments_count' => $payments->count(),
                'payments_total' => (float) $payments->where('status', 'paid')->sum('amount'),
                'last_activity_at' => $lastActivity,
                'last_login_at' => $lastLogin,
            ],
            'courses' => $courses,
            'certificates' => $certificates,
            'payments' => $payments,
            'login_history' => $loginHistory,
        ]);
    }

    public function update(Request $request, User $user)
    {
        if ($user->role !== 'learner') {
            return response()->json(['message' => 'Cet utilisateur n\'est pas un apprenant.'], 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        $user->update($data);

        Audit::log(
            'user.update',
            "Modification de l'apprenant {$user->name} ({$user->email})",
            $user,
            ['email' => $user->email, 'name' => $user->name]
        );

        return response()->json([
            'message' => 'Informations mises à jour.',
            'user' => $user->fresh(),
        ]);
    }

    public function toggleBlock(User $user)
    {
        if ($user->role !== 'learner') {
            return response()->json(['message' => 'Cet utilisateur n\'est pas un apprenant.'], 404);
        }

        if ($user->blocked_at) {
            $user->update(['blocked_at' => null]);
            $message = 'Compte réactivé.';
            $action = 'user.unblock';
        } else {
            $user->update(['blocked_at' => now()]);
            $message = 'Compte suspendu.';
            $action = 'user.block';
            $user->tokens()->delete();
        }

        Audit::log(
            $action,
            "{$message} {$user->name} ({$user->email})",
            $user,
            ['target_email' => $user->email]
        );

        if ($action === 'user.block') {
            Notify::send(
                $user,
                'account',
                'Votre compte a été suspendu. Contactez le support si vous pensez qu\'il s\'agit d\'une erreur.'
            );
        }

        return response()->json(['message' => $message, 'user' => $user->fresh()]);
    }

    /** Révoque toutes les sessions actives de l'apprenant. */
    public function forceLogout(User $user)
    {
        if ($user->role !== 'learner') {
            return response()->json(['message' => 'Cet utilisateur n\'est pas un apprenant.'], 404);
        }

        $revoked = $user->tokens()->count();
        $user->tokens()->delete();

        Audit::log(
            'user.force_logout',
            "Déconnexion forcée de {$user->name} ({$user->email})",
            $user,
            ['target_email' => $user->email, 'tokens_revoked' => $revoked]
        );

        return response()->json([
            'message' => $revoked > 0
                ? 'Apprenant déconnecté.'
                : 'Aucune session active à révoquer.',
            'tokens_revoked' => $revoked,
        ]);
    }

    /** Envoie un e-mail de réinitialisation de mot de passe à l'apprenant. */
    public function resetPassword(User $user)
    {
        if ($user->role !== 'learner') {
            return response()->json(['message' => 'Cet utilisateur n\'est pas un apprenant.'], 404);
        }

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        Audit::log(
            'user.password_reset',
            "Demande de réinitialisation du mot de passe pour {$user->name} ({$user->email})",
            $user,
            ['target_email' => $user->email, 'mail_status' => $status]
        );

        Notify::send(
            $user,
            'password_reset',
            'Un lien de réinitialisation de mot de passe vous a été envoyé par e-mail. Vérifiez votre boîte de réception.'
        );

        return response()->json([
            'message' => $status === Password::RESET_LINK_SENT
                ? 'E-mail de réinitialisation envoyé à l\'apprenant.'
                : 'Demande enregistrée. Vérifiez la configuration e-mail si le message n\'arrive pas.',
            'status' => $status === Password::RESET_LINK_SENT ? 'sent' : 'accepted',
        ]);
    }
}
