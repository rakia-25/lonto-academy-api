<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ExamAttempt extends Model
{
    protected $fillable = [
        'exam_id', 'user_id', 'question_order', 'snapshot', 'answers', 'answer_files',
        'started_at', 'expires_at', 'submitted_at', 'score', 'passed', 'status', 'manual_scores',
        'focus_violations',
    ];

    protected $casts = [
        'question_order' => 'array',
        'snapshot'       => 'array',
        'answers'        => 'array',
        'answer_files'   => 'array',
        'manual_scores'  => 'array',
        'started_at'     => 'datetime',
        'expires_at'     => 'datetime',
        'submitted_at'   => 'datetime',
        'passed'         => 'boolean',
        'score'          => 'integer',
        'focus_violations' => 'integer',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'in_progress' && ! $this->submitted_at;
    }

    public function isExpired(): bool
    {
        return $this->isActive() && now()->greaterThan($this->expires_at);
    }

    public function remainingSeconds(): int
    {
        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }

    /**
     * Instantané des paramètres + questions au démarrage de la tentative.
     * Les copies soumises restent figées même si l'admin modifie l'examen ensuite.
     */
    public static function buildSnapshot(Exam $exam): array
    {
        $exam->loadMissing('questions');

        return [
            'exam' => [
                'id'                  => $exam->id,
                'title'               => $exam->title,
                'pass_score'          => (int) $exam->pass_score,
                'duration_minutes'    => (int) $exam->duration_minutes,
                'certificate_type'    => $exam->certificate_type ?? 'certificat',
                'certificate_design'  => $exam->certificate_design,
                'shuffle_options'     => (bool) $exam->shuffle_options,
            ],
            'questions' => $exam->questions->map(fn ($q) => [
                'id'             => (int) $q->id,
                'type'           => $q->type ?? 'mcq',
                'question'       => $q->question,
                'options'        => $q->options ?? [],
                'correct_answer' => $q->correct_answer,
                'points'         => max(1, (int) $q->points),
            ])->values()->all(),
        ];
    }

    /** Paramètres d'examen figés pour cette tentative. */
    public function snapshotExamMeta(): array
    {
        $meta = $this->snapshot['exam'] ?? null;
        if (is_array($meta) && isset($meta['pass_score'])) {
            return $meta;
        }

        $this->loadMissing('exam');

        return [
            'id'                 => $this->exam?->id,
            'title'              => $this->exam?->title,
            'pass_score'         => (int) ($this->exam?->pass_score ?? 70),
            'duration_minutes'   => (int) ($this->exam?->duration_minutes ?? 30),
            'certificate_type'   => $this->exam?->certificate_type ?? 'certificat',
            'certificate_design' => $this->exam?->certificate_design,
            'shuffle_options'    => (bool) ($this->exam?->shuffle_options ?? true),
        ];
    }

    /**
     * Questions figées pour notation / consultation.
     * Fallback : questions live (anciennes tentatives sans snapshot).
     *
     * @return Collection<int, object>
     */
    public function questionsForAttempt(): Collection
    {
        $snapQuestions = $this->snapshot['questions'] ?? null;

        if (is_array($snapQuestions) && count($snapQuestions) > 0) {
            return collect($snapQuestions)->map(fn ($q) => (object) [
                'id'             => (int) ($q['id'] ?? 0),
                'type'           => $q['type'] ?? 'mcq',
                'question'       => $q['question'] ?? '',
                'options'        => $q['options'] ?? [],
                'correct_answer' => $q['correct_answer'] ?? null,
                'points'         => max(1, (int) ($q['points'] ?? 1)),
            ]);
        }

        $this->loadMissing('exam.questions');

        return ($this->exam?->questions ?? collect())->map(fn ($q) => (object) [
            'id'             => (int) $q->id,
            'type'           => $q->type ?? 'mcq',
            'question'       => $q->question,
            'options'        => $q->options ?? [],
            'correct_answer' => $q->correct_answer,
            'points'         => max(1, (int) $q->points),
        ]);
    }

    public function questionById($id): ?object
    {
        return $this->questionsForAttempt()->first(
            fn ($q) => (string) $q->id === (string) $id
        );
    }

    /**
     * Fusionne des réponses sans réindexer les clés.
     * Ne jamais utiliser array_merge() ici : PHP réindexe les clés numériques
     * (ids de questions) en 0,1,2… et décale toutes les réponses.
     */
    public static function mergeAnswerMaps(?array $existing, array $incoming): array
    {
        $merged = $existing ?? [];
        foreach ($incoming as $key => $value) {
            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * Réponses indexées par id de question.
     * Répare aussi les anciennes copies corrompues par array_merge :
     * chaque sauvegarde EMPILAIT les réponses réindexées 0..n-1, donc le
     * tableau stocké peut contenir plus d'entrées que de questions.
     */
    public function answersByQuestionId(): array
    {
        $raw = $this->answers ?? [];
        if ($raw === []) {
            return [];
        }

        // Clés = ids de questions : données saines, rien à faire.
        if (array_keys($raw) !== range(0, count($raw) - 1)) {
            return $this->stringKeys($raw);
        }

        $repaired = $this->repairCorruptedAnswers(array_values($raw));

        if ($repaired !== null) {
            if ($this->exists) {
                $this->forceFill(['answers' => $repaired])->saveQuietly();
            }

            return $repaired;
        }

        // Irrécupérable : ne surtout pas afficher de mauvais appariements.
        return [];
    }

    public function answerFilesByQuestionId(): array
    {
        return $this->stringKeys($this->answer_files ?? []);
    }

    public function manualScoresByQuestionId(): array
    {
        return $this->stringKeys($this->manual_scores ?? []);
    }

    public function answerFor($questionId): mixed
    {
        $answers = $this->answersByQuestionId();

        return $answers[(string) $questionId] ?? $answers[$questionId] ?? null;
    }

    /**
     * Le front envoyait à chaque sauvegarde la totalité des réponses,
     * triées par id croissant (clés numériques JS) ; array_merge les
     * empilait en fin de tableau. Les N dernières entrées correspondent
     * donc aux N questions hors dépôt de fichier, ids croissants.
     * Chaque réponse de QCM est validée contre les options de sa question.
     */
    private function repairCorruptedAnswers(array $list): ?array
    {
        $questions = $this->questionsForAttempt();
        if ($questions->isEmpty()) {
            return null;
        }

        $answerable = $questions
            ->filter(fn ($q) => ($q->type ?? 'mcq') !== 'file')
            ->sortBy('id')
            ->values();

        $count = $answerable->count();
        if ($count === 0 || count($list) < $count) {
            return null;
        }

        $suffix = array_slice($list, -$count);
        $fixed = [];

        foreach ($answerable as $i => $q) {
            $value = $suffix[$i];
            if (($q->type ?? 'mcq') === 'mcq'
                && $value !== null
                && $value !== ''
                && ! in_array($value, $q->options ?? [], true)
            ) {
                return null;
            }
            $fixed[(string) $q->id] = $value;
        }

        return $fixed;
    }

    private function stringKeys(array $map): array
    {
        $normalized = [];
        foreach ($map as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
