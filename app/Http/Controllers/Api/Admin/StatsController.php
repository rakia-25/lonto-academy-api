<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\ExerciseSubmission;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index()
    {
        $totalUsers       = User::where('role', 'learner')->count();
        $totalCourses     = Course::count();
        $totalPublished   = Course::where('is_published', true)->count();
        $totalRevenue     = Payment::where('status', 'paid')->sum('amount');
        $totalEnrollments = Enrollment::count();

        $enrollmentsPerMonth = Enrollment::selectRaw('MONTH(created_at) as month, COUNT(*) as total')
            ->whereYear('created_at', now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $revenuePerMonth = Payment::selectRaw('MONTH(created_at) as month, SUM(amount) as total')
            ->where('status', 'paid')
            ->whereYear('created_at', now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $newUsersPerMonth = User::selectRaw('MONTH(created_at) as month, COUNT(*) as total')
            ->where('role', 'learner')
            ->whereYear('created_at', now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $topCourses = Course::withCount('enrollments')
            ->orderBy('enrollments_count', 'desc')
            ->take(5)
            ->get(['id', 'title', 'category', 'level', 'price']);

        $pendingExercises = ExerciseSubmission::where('status', 'pending')->count();

        // Statuts possibles selon l'existant : pending_review
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
                'count'  => (int) $row->count,
                'total'  => (float) $row->total,
            ]);

        $freeEnrollments = Enrollment::whereHas('course', fn ($q) => $q->where('price', '<=', 0))->count();
        $paidEnrollments = max(0, $totalEnrollments - $freeEnrollments);

        return response()->json([
            'total_users'           => $totalUsers,
            'total_courses'         => $totalCourses,
            'total_published'       => $totalPublished,
            'total_revenue'         => $totalRevenue,
            'total_enrollments'     => $totalEnrollments,
            'enrollments_per_month' => $enrollmentsPerMonth,
            'revenue_per_month'     => $revenuePerMonth,
            'new_users_per_month'   => $newUsersPerMonth,
            'top_courses'           => $topCourses,
            'pending_exercise_submissions' => $pendingExercises,
            'pending_exam_reviews'  => $pendingExamReviews,
            'exam_pass_rate'        => $examPassRate,
            'exam_attempts_total'   => ExamAttempt::whereNotNull('submitted_at')->count(),
            'certificates_count'    => $certificatesCount,
            'payments_by_method'    => $paymentsByMethod,
            'free_vs_paid_enrollments' => [
                'free' => $freeEnrollments,
                'paid' => $paidEnrollments,
            ],
        ]);
    }
}
