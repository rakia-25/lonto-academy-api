<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\ExerciseSubmission;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public const METRICS = [
        'payments_by_method' => 'Paiements par méthode',
        'payments_by_status' => 'Paiements par statut',
        'enrollments_by_category' => 'Inscriptions par catégorie',
        'enrollments_by_level' => 'Inscriptions par niveau',
        'free_vs_paid' => 'Inscriptions gratuites / payantes',
        'certificates_by_type' => 'Certificats par type',
        'exam_results' => 'Résultats d\'examens',
        'exercise_status' => 'Exercices par statut',
        'courses_by_level' => 'Cours par niveau',
        'courses_by_status' => 'Cours publiés / brouillons',
    ];

    private const METHOD_LABELS = [
        'nita' => 'Nita',
        'amana' => 'Amana',
        'other' => 'Autre',
    ];

    private const STATUS_LABELS = [
        'paid' => 'Payé',
        'pending' => 'En attente',
        'failed' => 'Échoué',
    ];

    private const LEVEL_LABELS = [
        'debutant' => 'Débutant',
        'moyen' => 'Moyen',
        'avance' => 'Avancé',
    ];

    private const CATEGORY_LABELS = [
        'bureautique' => 'Bureautique',
        'sig' => 'SIG',
    ];

    private const CERT_LABELS = [
        'certificat' => 'Certificat',
        'attestation' => 'Attestation',
    ];

    private const EXERCISE_STATUS_LABELS = [
        'pending' => 'En attente',
        'validated' => 'Validé',
        'rejected' => 'Rejeté',
    ];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'metric' => ['nullable', Rule::in(array_keys(self::METRICS))],
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'category' => 'nullable|string|max:50',
            'level' => 'nullable|string|max:50',
            'course_id' => 'nullable|integer|exists:courses,id',
            'period' => ['nullable', Rule::in(['7d', '30d', '90d', 'year', 'all', 'custom'])],
        ]);

        $metric = $validated['metric'] ?? 'payments_by_method';
        [$from, $to] = $this->resolvePeriod(
            $validated['period'] ?? '30d',
            $validated['from'] ?? null,
            $validated['to'] ?? null
        );

        $filters = [
            'category' => $validated['category'] ?? null,
            'level' => $validated['level'] ?? null,
            'course_id' => isset($validated['course_id']) ? (int) $validated['course_id'] : null,
        ];

        $slices = $this->buildSlices($metric, $from, $to, $filters);
        $total = array_sum(array_column($slices, 'value'));

        $slices = array_map(function (array $slice) use ($total) {
            $slice['percent'] = $total > 0
                ? round(($slice['value'] / $total) * 100, 1)
                : 0;

            return $slice;
        }, $slices);

        return response()->json([
            'metric' => $metric,
            'title' => self::METRICS[$metric],
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'total' => $total,
            'slices' => array_values($slices),
            'hardest_questions' => $this->hardestQuestions($from, $to, $filters),
            'meta' => [
                'metrics' => collect(self::METRICS)
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                    ->values(),
                'categories' => Course::query()
                    ->whereNotNull('category')
                    ->distinct()
                    ->orderBy('category')
                    ->pluck('category')
                    ->map(fn ($c) => [
                        'key' => $c,
                        'label' => self::CATEGORY_LABELS[$c] ?? ucfirst((string) $c),
                    ])
                    ->values(),
                'levels' => collect(self::LEVEL_LABELS)
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                    ->values(),
                'courses' => Course::query()
                    ->orderBy('title')
                    ->get(['id', 'title'])
                    ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title]),
            ],
        ]);
    }

    /** @return array{0:?Carbon,1:?Carbon} */
    private function resolvePeriod(string $period, ?string $from, ?string $to): array
    {
        $end = now()->endOfDay();

        return match ($period) {
            '7d' => [now()->subDays(6)->startOfDay(), $end],
            '30d' => [now()->subDays(29)->startOfDay(), $end],
            '90d' => [now()->subDays(89)->startOfDay(), $end],
            'year' => [now()->startOfYear(), $end],
            'custom' => [
                $from ? Carbon::parse($from)->startOfDay() : null,
                $to ? Carbon::parse($to)->endOfDay() : $end,
            ],
            default => [null, null], // all
        };
    }

    private function buildSlices(string $metric, ?Carbon $from, ?Carbon $to, array $filters): array
    {
        return match ($metric) {
            'payments_by_method' => $this->paymentsByMethod($from, $to, $filters),
            'payments_by_status' => $this->paymentsByStatus($from, $to, $filters),
            'enrollments_by_category' => $this->enrollmentsByCourseField('category', self::CATEGORY_LABELS, $from, $to, $filters),
            'enrollments_by_level' => $this->enrollmentsByCourseField('level', self::LEVEL_LABELS, $from, $to, $filters),
            'free_vs_paid' => $this->freeVsPaid($from, $to, $filters),
            'certificates_by_type' => $this->certificatesByType($from, $to, $filters),
            'exam_results' => $this->examResults($from, $to, $filters),
            'exercise_status' => $this->exerciseStatus($from, $to, $filters),
            'courses_by_level' => $this->coursesByField('level', self::LEVEL_LABELS, $filters),
            'courses_by_status' => $this->coursesByStatus($filters),
            default => [],
        };
    }

    private function applyDate($query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from) {
            $query->where($column, '>=', $from);
        }
        if ($to) {
            $query->where($column, '<=', $to);
        }
    }

    private function applyCourseFilters($query, array $filters, string $courseRelation = 'course'): void
    {
        if (! empty($filters['course_id'])) {
            $query->where('course_id', $filters['course_id']);
        }

        if (! empty($filters['category']) || ! empty($filters['level'])) {
            $query->whereHas($courseRelation, function ($q) use ($filters) {
                if (! empty($filters['category'])) {
                    $q->where('category', $filters['category']);
                }
                if (! empty($filters['level'])) {
                    $q->where('level', $filters['level']);
                }
            });
        }
    }

    private function paymentsByMethod(?Carbon $from, ?Carbon $to, array $filters): array
    {
        $query = Payment::query()->where('status', 'paid');
        $this->applyDate($query, 'created_at', $from, $to);
        $this->applyCourseFilters($query, $filters);

        $rows = $query
            ->select('method', DB::raw('COUNT(*) as value'), DB::raw('SUM(amount) as amount'))
            ->groupBy('method')
            ->get();

        return $rows->map(fn ($row) => [
            'key' => $row->method ?: 'other',
            'label' => self::METHOD_LABELS[$row->method] ?? ucfirst((string) $row->method),
            'value' => (int) $row->value,
            'amount' => (float) $row->amount,
        ])->all();
    }

    private function paymentsByStatus(?Carbon $from, ?Carbon $to, array $filters): array
    {
        $query = Payment::query();
        $this->applyDate($query, 'created_at', $from, $to);
        $this->applyCourseFilters($query, $filters);

        $rows = $query
            ->select('status', DB::raw('COUNT(*) as value'))
            ->groupBy('status')
            ->get();

        return $rows->map(fn ($row) => [
            'key' => $row->status,
            'label' => self::STATUS_LABELS[$row->status] ?? ucfirst((string) $row->status),
            'value' => (int) $row->value,
        ])->all();
    }

    private function enrollmentsByCourseField(
        string $field,
        array $labels,
        ?Carbon $from,
        ?Carbon $to,
        array $filters
    ): array {
        $query = Enrollment::query()->join('courses', 'enrollments.course_id', '=', 'courses.id');
        $this->applyDate($query, 'enrollments.created_at', $from, $to);

        if (! empty($filters['course_id'])) {
            $query->where('enrollments.course_id', $filters['course_id']);
        }
        if (! empty($filters['category'])) {
            $query->where('courses.category', $filters['category']);
        }
        if (! empty($filters['level'])) {
            $query->where('courses.level', $filters['level']);
        }

        $rows = $query
            ->select("courses.{$field} as bucket", DB::raw('COUNT(*) as value'))
            ->groupBy("courses.{$field}")
            ->get();

        return $rows->map(fn ($row) => [
            'key' => $row->bucket ?: 'autre',
            'label' => $labels[$row->bucket] ?? ucfirst((string) ($row->bucket ?: 'Autre')),
            'value' => (int) $row->value,
        ])->all();
    }

    private function freeVsPaid(?Carbon $from, ?Carbon $to, array $filters): array
    {
        $base = Enrollment::query()->join('courses', 'enrollments.course_id', '=', 'courses.id');
        $this->applyDate($base, 'enrollments.created_at', $from, $to);

        if (! empty($filters['course_id'])) {
            $base->where('enrollments.course_id', $filters['course_id']);
        }
        if (! empty($filters['category'])) {
            $base->where('courses.category', $filters['category']);
        }
        if (! empty($filters['level'])) {
            $base->where('courses.level', $filters['level']);
        }

        $free = (clone $base)->where('courses.price', '<=', 0)->count();
        $paid = (clone $base)->where('courses.price', '>', 0)->count();

        return [
            ['key' => 'free', 'label' => 'Gratuit', 'value' => $free],
            ['key' => 'paid', 'label' => 'Payant', 'value' => $paid],
        ];
    }

    private function certificatesByType(?Carbon $from, ?Carbon $to, array $filters): array
    {
        $query = Certificate::query();
        $this->applyDate($query, 'issued_at', $from, $to);
        $this->applyCourseFilters($query, $filters);

        $rows = $query
            ->select(DB::raw("COALESCE(type, 'certificat') as bucket"), DB::raw('COUNT(*) as value'))
            ->groupBy(DB::raw("COALESCE(type, 'certificat')"))
            ->get();

        return $rows->map(fn ($row) => [
            'key' => $row->bucket,
            'label' => self::CERT_LABELS[$row->bucket] ?? ucfirst((string) $row->bucket),
            'value' => (int) $row->value,
        ])->all();
    }

    private function examResults(?Carbon $from, ?Carbon $to, array $filters): array
    {
        $query = ExamAttempt::query()
            ->whereNotNull('submitted_at')
            ->whereNotNull('passed');

        $this->applyDate($query, 'submitted_at', $from, $to);

        if (! empty($filters['course_id']) || ! empty($filters['category']) || ! empty($filters['level'])) {
            $query->whereHas('exam.course', function ($q) use ($filters) {
                if (! empty($filters['course_id'])) {
                    $q->where('id', $filters['course_id']);
                }
                if (! empty($filters['category'])) {
                    $q->where('category', $filters['category']);
                }
                if (! empty($filters['level'])) {
                    $q->where('level', $filters['level']);
                }
            });
        }

        $passed = (clone $query)->where('passed', true)->count();
        $failed = (clone $query)->where('passed', false)->count();

        return [
            ['key' => 'passed', 'label' => 'Réussis', 'value' => $passed],
            ['key' => 'failed', 'label' => 'Échoués', 'value' => $failed],
        ];
    }

    private function exerciseStatus(?Carbon $from, ?Carbon $to, array $filters): array
    {
        $query = ExerciseSubmission::query();
        $this->applyDate($query, 'created_at', $from, $to);

        if (! empty($filters['course_id']) || ! empty($filters['category']) || ! empty($filters['level'])) {
            $query->whereHas('exercise.chapter.course', function ($q) use ($filters) {
                if (! empty($filters['course_id'])) {
                    $q->where('id', $filters['course_id']);
                }
                if (! empty($filters['category'])) {
                    $q->where('category', $filters['category']);
                }
                if (! empty($filters['level'])) {
                    $q->where('level', $filters['level']);
                }
            });
        }

        $rows = $query
            ->select('status', DB::raw('COUNT(*) as value'))
            ->groupBy('status')
            ->get();

        return $rows->map(fn ($row) => [
            'key' => $row->status,
            'label' => self::EXERCISE_STATUS_LABELS[$row->status] ?? ucfirst((string) $row->status),
            'value' => (int) $row->value,
        ])->all();
    }

    private function coursesByField(string $field, array $labels, array $filters): array
    {
        $query = Course::query();
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['level'])) {
            $query->where('level', $filters['level']);
        }
        if (! empty($filters['course_id'])) {
            $query->where('id', $filters['course_id']);
        }

        $rows = $query
            ->select($field.' as bucket', DB::raw('COUNT(*) as value'))
            ->groupBy($field)
            ->get();

        return $rows->map(fn ($row) => [
            'key' => $row->bucket ?: 'autre',
            'label' => $labels[$row->bucket] ?? ucfirst((string) ($row->bucket ?: 'Autre')),
            'value' => (int) $row->value,
        ])->all();
    }

    private function coursesByStatus(array $filters): array
    {
        $query = Course::query();
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['level'])) {
            $query->where('level', $filters['level']);
        }
        if (! empty($filters['course_id'])) {
            $query->where('id', $filters['course_id']);
        }

        $published = (clone $query)->where('is_published', true)->count();
        $draft = (clone $query)->where('is_published', false)->count();

        return [
            ['key' => 'published', 'label' => 'Publiés', 'value' => $published],
            ['key' => 'draft', 'label' => 'Brouillons', 'value' => $draft],
        ];
    }

    /**
     * Questions d'examen les plus ratées (QCM + questions notées).
     *
     * @return list<array{question_id:int,question:string,exam_title:?string,course_title:?string,attempts:int,failed:int,fail_rate:float}>
     */
    private function hardestQuestions(?Carbon $from, ?Carbon $to, array $filters, int $limit = 10): array
    {
        $query = ExamAttempt::query()
            ->with(['exam:id,course_id,title', 'exam.course:id,title,category,level'])
            ->whereNotNull('submitted_at')
            ->whereIn('status', ['graded', 'submitted', 'expired']);

        $this->applyDate($query, 'submitted_at', $from, $to);

        if (! empty($filters['course_id']) || ! empty($filters['category']) || ! empty($filters['level'])) {
            $query->whereHas('exam.course', function ($q) use ($filters) {
                if (! empty($filters['course_id'])) {
                    $q->where('id', $filters['course_id']);
                }
                if (! empty($filters['category'])) {
                    $q->where('category', $filters['category']);
                }
                if (! empty($filters['level'])) {
                    $q->where('level', $filters['level']);
                }
            });
        }

        $attempts = $query->latest('submitted_at')->limit(500)->get();
        $agg = [];

        foreach ($attempts as $attempt) {
            $questions = $attempt->questionsForAttempt();
            $answers = $attempt->answersByQuestionId();
            $manualScores = $attempt->manualScoresByQuestionId();
            $courseTitle = $attempt->exam?->course?->title;
            $examTitle = $attempt->exam?->title;

            foreach ($questions as $question) {
                $qid = (int) $question->id;
                if ($qid <= 0) {
                    continue;
                }

                $type = $question->type ?? 'mcq';
                $failed = null;

                if ($type === 'mcq') {
                    $given = $answers[(string) $qid] ?? null;
                    $isCorrect = $given !== null
                        && (string) $given === (string) ($question->correct_answer ?? '');
                    $failed = ! $isCorrect;
                } elseif (array_key_exists((string) $qid, $manualScores)) {
                    $points = max(1, (int) $question->points);
                    $earned = (float) $manualScores[(string) $qid];
                    $failed = $earned < $points;
                } else {
                    continue;
                }

                if (! isset($agg[$qid])) {
                    $agg[$qid] = [
                        'question_id' => $qid,
                        'question' => (string) ($question->question ?? ''),
                        'exam_title' => $examTitle,
                        'course_title' => $courseTitle,
                        'attempts' => 0,
                        'failed' => 0,
                    ];
                }

                $agg[$qid]['attempts']++;
                if ($failed) {
                    $agg[$qid]['failed']++;
                }
            }
        }

        return collect($agg)
            ->filter(fn ($row) => $row['attempts'] >= 1)
            ->map(function (array $row) {
                $row['fail_rate'] = $row['attempts'] > 0
                    ? round(($row['failed'] / $row['attempts']) * 100, 1)
                    : 0;

                return $row;
            })
            ->sortByDesc(fn ($row) => [$row['fail_rate'], $row['failed'], $row['attempts']])
            ->take($limit)
            ->values()
            ->all();
    }
}
