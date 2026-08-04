<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\ExamAttempt;
use App\Models\ExerciseSubmission;
use App\Models\LessonProgress;
use App\Models\Payment;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\CourseProgress;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::where('role', 'learner')
            ->withCount('enrollments')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($users);
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
                $quizzes[] = [
                    'quiz_id'        => $chapter->quiz->id,
                    'chapter_title'  => $chapter->title,
                    'title'          => $chapter->quiz->title,
                    'pass_score'     => $chapter->quiz->pass_score,
                    'passed'         => $attempts->contains(fn ($a) => $a->passed),
                    'best_score'     => $best?->score,
                    'attempts_count' => $attempts->count(),
                    'attempts'       => $attempts->take(10)->map(fn ($a) => [
                        'id'         => $a->id,
                        'score'      => $a->score,
                        'passed'     => (bool) $a->passed,
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
                    'exercise_id'   => $chapter->exercise->id,
                    'chapter_title' => $chapter->title,
                    'title'         => $chapter->exercise->title,
                    'submitted'     => (bool) $submission?->submitted_at,
                    'submitted_at'  => $submission?->submitted_at,
                    'status'        => $submission?->status ?? null,
                    'score'         => $submission?->score,
                    'feedback'      => $submission?->feedback,
                    'corrected_at'  => $submission?->corrected_at,
                    'file_path'     => $submission?->file_path,
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
                    'id'         => $enrollment->id,
                    'type'       => $enrollment->type,
                    'created_at' => $enrollment->created_at,
                    'expires_at' => $enrollment->expires_at,
                ],
                'course' => [
                    'id'    => $course->id,
                    'title' => $course->title,
                    'slug'  => $course->slug,
                    'level' => $course->level,
                    'price' => $course->price,
                ],
                'stats'            => $stats,
                'exam_eligibility' => $eligibility,
                'exam_summary'     => [
                    'title'          => $bestExamAttempt?->exam?->title
                        ?? $examAttempts->first()?->exam?->title,
                    'pass_score'     => $bestExamAttempt?->exam?->pass_score
                        ?? $examAttempts->first()?->exam?->pass_score,
                    'best_score'     => $bestExamAttempt?->score,
                    'passed'         => $examPassed,
                    'pending_review' => $examPendingReview,
                    'attempts_count' => $closedAttempts->count(),
                ],
                'quizzes'          => $quizzes,
                'exercises'        => $exercises,
                'exam_attempts'    => $examAttempts->map(fn ($a) => [
                    'id'           => $a->id,
                    'exam_id'      => $a->exam_id,
                    'exam_title'   => $a->exam?->title,
                    'pass_score'   => $a->exam?->pass_score,
                    'score'        => $a->score,
                    'passed'       => (bool) $a->passed,
                    'status'       => $a->status,
                    'started_at'   => $a->started_at,
                    'submitted_at' => $a->submitted_at,
                ])->values(),
                'lessons' => [
                    'total'     => $stats['total_lessons'],
                    'completed' => $stats['completed_lessons'],
                    'last_at'   => $lastLessonAt,
                ],
            ];
        }

        $certificates = Certificate::where('user_id', $user->id)
            ->with('course:id,title,slug')
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn ($c) => [
                'id'                => $c->id,
                'course_title'      => $c->course?->title,
                'course_slug'       => $c->course?->slug,
                'issued_at'         => $c->issued_at,
                'verification_code' => $c->verification_code,
            ]);

        $payments = Payment::where('user_id', $user->id)
            ->with('course:id,title')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($p) => [
                'id'           => $p->id,
                'course_title' => $p->course?->title,
                'amount'       => $p->amount,
                'method'       => $p->method,
                'status'       => $p->status,
                'reference'    => $p->reference,
                'created_at'   => $p->created_at,
            ]);

        $allQuizAttempts = QuizAttempt::where('user_id', $user->id)->count();
        $allExerciseSubs = ExerciseSubmission::where('user_id', $user->id)
            ->whereNotNull('submitted_at')
            ->count();
        $allExamAttempts = ExamAttempt::where('user_id', $user->id)->count();

        $lastActivity = $activityDates->filter()->sortDesc()->first();

        return response()->json([
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'avatar'     => $user->avatar,
                'blocked_at' => $user->blocked_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
            'summary' => [
                'enrollments_count'   => $user->enrollments->count(),
                'courses_completed'   => $completedCourses,
                'quiz_attempts'       => $allQuizAttempts,
                'exercises_submitted' => $allExerciseSubs,
                'exam_attempts'       => $allExamAttempts,
                'certificates_count'  => $certificates->count(),
                'payments_count'      => $payments->count(),
                'payments_total'      => (float) $payments->where('status', 'paid')->sum('amount'),
                'last_activity_at'    => $lastActivity,
            ],
            'courses'      => $courses,
            'certificates' => $certificates,
            'payments'     => $payments,
        ]);
    }

    public function toggleBlock(User $user)
    {
        if ($user->blocked_at) {
            $user->update(['blocked_at' => null]);
            $message = 'Utilisateur débloqué.';
        } else {
            $user->update(['blocked_at' => now()]);
            $message = 'Utilisateur bloqué.';
        }

        return response()->json(['message' => $message, 'user' => $user]);
    }
}
