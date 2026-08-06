<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\ExerciseSubmission;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index()
    {
        $totalUsers = User::where('role', 'learner')->count();
        $totalCourses = Course::count();
        $totalPublished = Course::where('is_published', true)->count();
        $totalRevenue = Payment::where('status', 'paid')->sum('amount');
        $totalEnrollments = Enrollment::count();

        $monthRevenue = (float) Payment::where('status', 'paid')
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('amount');

        $enrollmentsPerMonth = $this->monthlyCounts(Enrollment::query(), now()->year);
        $revenuePerMonth = $this->monthlySums(
            Payment::query()->where('status', 'paid'),
            'amount',
            now()->year
        );
        $newUsersPerMonth = $this->monthlyCounts(
            User::query()->where('role', 'learner'),
            now()->year
        );

        $topCourses = Course::withCount('enrollments')
            ->orderBy('enrollments_count', 'desc')
            ->take(5)
            ->get(['id', 'title', 'category', 'level', 'price']);

        $pendingExercises = ExerciseSubmission::where('status', 'pending')->count();
        $pendingExamReviews = ExamAttempt::where('status', 'pending_review')->count();

        $gradedAttempts = ExamAttempt::whereIn('status', ['graded', 'submitted', 'expired'])
            ->whereNotNull('score')
            ->count();
        $passedAttempts = ExamAttempt::where('passed', true)->count();
        $examPassRate = $gradedAttempts > 0
            ? round(($passedAttempts / max(1, ExamAttempt::whereNotNull('submitted_at')->count())) * 100, 1)
            : 0;

        $certificatesCount = Certificate::count();

        $paymentsByMethod = Payment::where('status', 'paid')
            ->select('method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ]);

        $freeEnrollments = Enrollment::whereHas('course', fn ($q) => $q->where('price', '<=', 0))->count();
        $paidEnrollments = max(0, $totalEnrollments - $freeEnrollments);

        return response()->json([
            'total_users' => $totalUsers,
            'total_courses' => $totalCourses,
            'total_published' => $totalPublished,
            'total_revenue' => $totalRevenue,
            'month_revenue' => $monthRevenue,
            'avg_completion_rate' => $this->averageCompletionRate(),
            'total_enrollments' => $totalEnrollments,
            'enrollments_per_month' => $enrollmentsPerMonth,
            'enrollments_last_30_days' => $this->enrollmentsLast30Days(),
            'revenue_per_month' => $revenuePerMonth,
            'new_users_per_month' => $newUsersPerMonth,
            'top_courses' => $topCourses,
            'pending_exercise_submissions' => $pendingExercises,
            'pending_exam_reviews' => $pendingExamReviews,
            'exam_pass_rate' => $examPassRate,
            'exam_attempts_total' => ExamAttempt::whereNotNull('submitted_at')->count(),
            'certificates_count' => $certificatesCount,
            'payments_by_method' => $paymentsByMethod,
            'free_vs_paid_enrollments' => [
                'free' => $freeEnrollments,
                'paid' => $paidEnrollments,
            ],
            'recent_activity' => $this->recentActivity(),
        ]);
    }

    /** Expression mois compatible MySQL et PostgreSQL. */
    private function monthExpression(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? 'EXTRACT(MONTH FROM created_at)::int'
            : 'MONTH(created_at)';
    }

    private function dayExpression(string $column = 'created_at'): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "DATE({$column})"
            : "DATE({$column})";
    }

    private function monthlyCounts($query, int $year)
    {
        $month = $this->monthExpression();

        return $query
            ->selectRaw("{$month} as month, COUNT(*) as total")
            ->whereYear('created_at', $year)
            ->groupBy(DB::raw($month))
            ->orderBy('month')
            ->get();
    }

    private function monthlySums($query, string $column, int $year)
    {
        $month = $this->monthExpression();

        return $query
            ->selectRaw("{$month} as month, SUM({$column}) as total")
            ->whereYear('created_at', $year)
            ->groupBy(DB::raw($month))
            ->orderBy('month')
            ->get();
    }

    /** Inscriptions par jour sur les 30 derniers jours (jours manquants = 0). */
    private function enrollmentsLast30Days(): array
    {
        $from = now()->subDays(29)->startOfDay();
        $dayExpr = $this->dayExpression();

        $rows = Enrollment::query()
            ->selectRaw("{$dayExpr} as day, COUNT(*) as total")
            ->where('created_at', '>=', $from)
            ->groupBy(DB::raw($dayExpr))
            ->orderBy('day')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->day)->toDateString());

        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $date = $from->copy()->addDays($i);
            $key = $date->toDateString();
            $series[] = [
                'date' => $key,
                'label' => $date->format('d/m'),
                'total' => (int) ($rows[$key]->total ?? 0),
            ];
        }

        return $series;
    }

    /** Taux de complétion moyen basé sur les leçons terminées / total leçons. */
    private function averageCompletionRate(): float
    {
        $enrollments = Enrollment::query()->get(['user_id', 'course_id']);
        if ($enrollments->isEmpty()) {
            return 0;
        }

        $courseIds = $enrollments->pluck('course_id')->unique()->values();

        $lessonCounts = DB::table('lessons')
            ->join('chapters', 'lessons.chapter_id', '=', 'chapters.id')
            ->whereIn('chapters.course_id', $courseIds)
            ->select('chapters.course_id', DB::raw('COUNT(*) as total'))
            ->groupBy('chapters.course_id')
            ->pluck('total', 'course_id');

        $completed = DB::table('lesson_progress')
            ->join('lessons', 'lesson_progress.lesson_id', '=', 'lessons.id')
            ->join('chapters', 'lessons.chapter_id', '=', 'chapters.id')
            ->where('lesson_progress.completed', true)
            ->whereIn('chapters.course_id', $courseIds)
            ->select(
                'lesson_progress.user_id',
                'chapters.course_id',
                DB::raw('COUNT(*) as completed')
            )
            ->groupBy('lesson_progress.user_id', 'chapters.course_id')
            ->get()
            ->keyBy(fn ($row) => $row->user_id.'_'.$row->course_id);

        $sum = 0;
        $count = 0;

        foreach ($enrollments as $enrollment) {
            $total = (int) ($lessonCounts[$enrollment->course_id] ?? 0);
            if ($total === 0) {
                continue;
            }
            $key = $enrollment->user_id.'_'.$enrollment->course_id;
            $done = (int) ($completed[$key]->completed ?? 0);
            $sum += min(100, ($done / $total) * 100);
            $count++;
        }

        return $count > 0 ? round($sum / $count, 1) : 0;
    }

    /** Activité récente : inscriptions, paiements et certificats. */
    private function recentActivity(): array
    {
        $events = collect();

        Enrollment::with(['user:id,name', 'course:id,title'])
            ->latest()
            ->take(8)
            ->get()
            ->each(function (Enrollment $e) use ($events) {
                $name = $e->user?->name ?? 'Un apprenant';
                $course = $e->course?->title ?? 'un cours';
                $events->push([
                    'type' => 'enrollment',
                    'action' => 'enrollment.checkout',
                    'text' => "Nouvelle inscription : {$name} → « {$course} »",
                    'at' => $e->created_at?->toIso8601String(),
                ]);
            });

        Payment::with(['user:id,name'])
            ->where('status', 'paid')
            ->where('amount', '>', 0)
            ->latest()
            ->take(8)
            ->get()
            ->each(function (Payment $p) use ($events) {
                $name = $p->user?->name ?? 'Un apprenant';
                $amount = number_format((float) $p->amount, 0, ',', ' ').' FCFA';
                $events->push([
                    'type' => 'payment',
                    'action' => 'payment.received',
                    'text' => "Paiement reçu : {$amount} — {$name}",
                    'at' => $p->created_at?->toIso8601String(),
                ]);
            });

        Certificate::with(['user:id,name', 'course:id,title'])
            ->latest('issued_at')
            ->take(8)
            ->get()
            ->each(function (Certificate $c) use ($events) {
                $name = $c->user?->name ?? 'Un apprenant';
                $course = $c->course?->title ?? 'un cours';
                $events->push([
                    'type' => 'certificate',
                    'action' => 'certificate.issued',
                    'text' => "Certificat généré : {$name} — « {$course} »",
                    'at' => ($c->issued_at ?? $c->created_at)?->toIso8601String(),
                ]);
            });

        ActivityLog::query()
            ->whereIn('action', ['auth.register'])
            ->latest()
            ->take(5)
            ->get()
            ->each(function (ActivityLog $log) use ($events) {
                $events->push([
                    'type' => 'registration',
                    'action' => $log->action,
                    'text' => $log->description ?: 'Nouvelle inscription sur la plateforme',
                    'at' => $log->created_at?->toIso8601String(),
                ]);
            });

        return $events
            ->filter(fn ($e) => ! empty($e['at']))
            ->sortByDesc('at')
            ->unique(fn ($e) => ($e['type'] ?? '').'|'.($e['text'] ?? '').'|'.substr((string) ($e['at'] ?? ''), 0, 16))
            ->values()
            ->take(10)
            ->all();
    }
}
