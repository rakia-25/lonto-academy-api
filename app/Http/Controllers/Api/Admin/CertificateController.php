<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Support\Audit;
use App\Support\CertificateDesign;
use App\Support\CertificatePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    public function index(Request $request)
    {
        $query = Certificate::with([
            'user:id,name,email',
            'course:id,title,slug,category,level',
        ])->orderByDesc('issued_at');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', (int) $request->course_id);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('verification_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('course', function ($cq) use ($search) {
                        $cq->where('title', 'like', "%{$search}%");
                    });
            });
        }

        $certs = $query->paginate(min(100, max(10, (int) $request->get('per_page', 20))));

        return response()->json([
            'data' => collect($certs->items())->map(fn (Certificate $c) => $this->presentCertificate($c)),
            'meta' => [
                'current_page' => $certs->currentPage(),
                'last_page' => $certs->lastPage(),
                'per_page' => $certs->perPage(),
                'total' => $certs->total(),
            ],
            'filters' => [
                'courses' => Course::query()
                    ->orderBy('title')
                    ->get(['id', 'title'])
                    ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title]),
                'types' => [
                    ['key' => 'certificat', 'label' => 'Certificat'],
                    ['key' => 'attestation', 'label' => 'Attestation'],
                ],
            ],
        ]);
    }

    public function stats(Request $request)
    {
        $request->validate([
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);

        $courseId = $request->filled('course_id') ? (int) $request->course_id : null;

        $certsQuery = Certificate::query();
        if ($courseId) {
            $certsQuery->where('course_id', $courseId);
        }

        $attemptsQuery = ExamAttempt::query()
            ->whereNotNull('submitted_at')
            ->whereNotNull('passed')
            ->whereIn('status', ['graded', 'submitted', 'expired']);

        if ($courseId) {
            $attemptsQuery->whereHas('exam', fn ($q) => $q->where('course_id', $courseId));
        }

        $attemptsTotal = (clone $attemptsQuery)->count();
        $attemptsPassed = (clone $attemptsQuery)->where('passed', true)->count();
        $passRate = $attemptsTotal > 0
            ? round(($attemptsPassed / $attemptsTotal) * 100, 1)
            : 0;

        $avgScore = (clone $attemptsQuery)->whereNotNull('score')->avg('score');

        return response()->json([
            'certificates_count' => $certsQuery->count(),
            'certificates_by_type' => [
                'certificat' => (clone $certsQuery)->where(function ($q) {
                    $q->where('type', 'certificat')->orWhereNull('type');
                })->count(),
                'attestation' => (clone $certsQuery)->where('type', 'attestation')->count(),
            ],
            'exam_attempts_total' => $attemptsTotal,
            'exam_attempts_passed' => $attemptsPassed,
            'exam_pass_rate' => $passRate,
            'exam_avg_score' => $avgScore !== null ? round((float) $avgScore, 1) : null,
            'courses' => Course::query()
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title]),
        ]);
    }

    public function download(string $code)
    {
        $certificate = Certificate::with(['user', 'course'])
            ->where('verification_code', $code)
            ->firstOrFail();

        if (! $certificate->course) {
            return response()->json(['message' => 'Cours associé introuvable.'], 404);
        }

        $design = $this->resolveDesign($certificate);
        $type = ($certificate->type ?? 'certificat') === 'attestation' ? 'attestation' : 'certificat';

        [$pdf] = CertificatePdf::make(
            $certificate,
            $certificate->user,
            $certificate->course,
            $design
        );

        Audit::log(
            'certificate.download',
            "Consultation PDF certificat « {$certificate->course->title} » (admin)",
            $certificate,
            ['code' => $certificate->verification_code, 'admin' => true]
        );

        $filename = ($type === 'attestation' ? 'attestation-' : 'certificat-')
            .(Str::slug($certificate->course->title) ?: 'cours')
            .'-'.Str::slug($certificate->user?->name ?: 'apprenant')
            .'.pdf';

        return $pdf->download($filename);
    }

    private function presentCertificate(Certificate $c): array
    {
        return [
            'id' => $c->id,
            'verification_code' => $c->verification_code,
            'type' => $c->type ?? 'certificat',
            'issued_at' => $c->issued_at,
            'learner' => $c->user ? [
                'id' => $c->user->id,
                'name' => $c->user->name,
                'email' => $c->user->email,
            ] : null,
            'course' => $c->course ? [
                'id' => $c->course->id,
                'title' => $c->course->title,
                'slug' => $c->course->slug,
                'category' => $c->course->category,
                'level' => $c->course->level,
            ] : null,
        ];
    }

    private function resolveDesign(Certificate $certificate): array
    {
        $type = $certificate->type ?? 'certificat';

        if (! empty($certificate->design_snapshot) && is_array($certificate->design_snapshot)) {
            return CertificateDesign::normalize($certificate->design_snapshot, $type);
        }

        $exam = Exam::where('course_id', $certificate->course_id)->first();

        return CertificateDesign::forExam($exam);
    }
}
