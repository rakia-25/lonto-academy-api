<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Support\CertificateDesign;
use App\Support\CourseProgress;
use App\Support\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExamController extends Controller
{
    // Infos examen + éligibilité pour l'apprenant
    public function show(Request $request, Course $course)
    {
        $user = $request->user();

        if (! $course->isEnrolled($user)) {
            return response()->json(['message' => 'Vous devez être inscrit au cours.'], 403);
        }

        $exam = Exam::where('course_id', $course->id)
            ->where('is_published', true)
            ->withCount('questions')
            ->first();

        if (! $exam) {
            return response()->json(['exam' => null]);
        }

        $eligibility = CourseProgress::examEligibility($user, $course);

        $attempts = ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $user->id)
            ->orderByDesc('started_at')
            ->get();

        // Clôturer les tentatives expirées non soumises
        $attempts->filter(fn ($a) => $a->isExpired())
            ->each(fn ($a) => $this->finalizeAttempt($a));

        $attempts = ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $user->id)
            ->orderByDesc('started_at')
            ->get();

        $active = $attempts->first(fn ($a) => $a->isActive() && ! $a->isExpired());
        $submitted = $attempts->filter(fn ($a) => $a->status !== 'in_progress')->values();
        $best = $submitted->sortByDesc('score')->first();
        $passed = $submitted->contains(fn ($a) => $a->passed);
        $pendingReview = $submitted->contains(fn ($a) => $a->status === 'pending_review');

        $attemptsUsed = $submitted->count();
        $attemptsLeft = $exam->max_attempts !== null
            ? max(0, $exam->max_attempts - $attemptsUsed)
            : null;

        $certificate = Certificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        return response()->json([
            'exam' => [
                'id'               => $exam->id,
                'title'            => $exam->title,
                'description'      => $exam->description,
                'duration_minutes' => $exam->duration_minutes,
                'pass_score'       => $exam->pass_score,
                'max_attempts'     => $exam->max_attempts,
                'certificate_type' => $exam->certificate_type,
                'questions_count'  => $exam->questions_count,
            ],
            'eligibility'    => $eligibility,
            'attempts_used'  => $attemptsUsed,
            'attempts_left'  => $attemptsLeft,
            'best_score'     => $best?->score,
            'passed'         => $passed,
            'pending_review' => $pendingReview,
            'active_attempt' => $active ? $this->presentAttempt($active) : null,
            'attempts'       => $submitted->map(fn ($a) => [
                'id'           => $a->id,
                'score'        => $a->score,
                'passed'       => (bool) $a->passed,
                'status'       => $a->status,
                'submitted_at' => $a->submitted_at,
            ])->values(),
            'certificate'    => $certificate,
        ]);
    }

    // Démarrer une tentative (chronomètre lancé côté serveur)
    public function start(Request $request, Exam $exam)
    {
        $user = $request->user();
        $exam->load('course.chapters.lessons', 'course.chapters.exercise', 'questions');

        if (! $exam->is_published) {
            return response()->json(['message' => 'Cet examen n\'est pas disponible.'], 403);
        }

        $course = $exam->course;
        if (! $course || ! $course->isEnrolled($user)) {
            return response()->json(['message' => 'Vous devez être inscrit au cours.'], 403);
        }

        $eligibility = CourseProgress::examEligibility($user, $course);
        if (! $eligibility['eligible']) {
            return response()->json([
                'message' => 'Vous ne remplissez pas encore les conditions pour passer cet examen.',
                'reasons' => $eligibility['reasons'],
            ], 403);
        }

        if ($exam->questions->isEmpty()) {
            return response()->json(['message' => 'Cet examen ne contient aucune question.'], 422);
        }

        // Une fois l'examen réussi, plus aucune nouvelle tentative
        $alreadyPassed = ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $user->id)
            ->where('passed', true)
            ->exists();
        if ($alreadyPassed) {
            return response()->json([
                'message' => 'Vous avez déjà réussi cet examen. Aucune nouvelle tentative n\'est possible.',
            ], 403);
        }

        // Reprendre une tentative active non expirée
        $active = ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->first();

        if ($active) {
            if ($active->isExpired()) {
                $this->finalizeAttempt($active);
            } else {
                return response()->json([
                    'message' => 'Tentative en cours reprise.',
                    'attempt' => $this->presentAttempt($active),
                ]);
            }
        }

        // Pas de nouvelle tentative tant qu'une correction manuelle est en attente
        $pending = ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $user->id)
            ->where('status', 'pending_review')
            ->exists();
        if ($pending) {
            return response()->json([
                'message' => 'Votre précédente tentative est en cours de correction. Vous serez notifié du résultat.',
            ], 403);
        }

        // Limite de tentatives
        if ($exam->max_attempts !== null) {
            $used = ExamAttempt::where('exam_id', $exam->id)
                ->where('user_id', $user->id)
                ->where('status', '!=', 'in_progress')
                ->count();
            if ($used >= $exam->max_attempts) {
                return response()->json(['message' => 'Vous avez épuisé toutes vos tentatives pour cet examen.'], 403);
            }
        }

        // Ordre aléatoire des questions, propre à cette tentative / cet apprenant
        $order = $exam->questions->pluck('id')->shuffle()->values()->all();
        $snapshot = ExamAttempt::buildSnapshot($exam);

        $attempt = ExamAttempt::create([
            'exam_id'        => $exam->id,
            'user_id'        => $user->id,
            'question_order' => $order,
            'snapshot'       => $snapshot,
            'answers'        => [],
            'started_at'     => now(),
            'expires_at'     => now()->addMinutes($exam->duration_minutes),
            'status'         => 'in_progress',
        ]);

        return response()->json([
            'message' => 'Examen démarré. Bonne chance !',
            'attempt' => $this->presentAttempt($attempt),
        ], 201);
    }

    // Sauvegarde des réponses en cours d'examen
    public function saveAnswers(Request $request, ExamAttempt $attempt)
    {
        $user = $request->user();
        if ($attempt->user_id !== $user->id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if (! $attempt->isActive()) {
            return response()->json(['message' => 'Cette tentative est déjà terminée.'], 422);
        }

        if ($attempt->isExpired()) {
            $this->finalizeAttempt($attempt);
            return response()->json([
                'message' => 'Le temps est écoulé. Votre examen a été soumis automatiquement.',
                'attempt' => $attempt->fresh(),
                'expired' => true,
            ], 422);
        }

        $validated = $request->validate([
            'answers'   => 'required|array',
            'answers.*' => 'nullable|string',
        ]);

        $attempt->update([
            'answers' => ExamAttempt::mergeAnswerMaps($attempt->answers ?? [], $validated['answers']),
        ]);

        return response()->json([
            'message'           => 'Réponses sauvegardées.',
            'remaining_seconds' => $attempt->remainingSeconds(),
        ]);
    }

    /** Enregistre une perte de focus / changement d'onglet (anti-triche léger). */
    public function reportViolation(Request $request, ExamAttempt $attempt)
    {
        $user = $request->user();
        if ($attempt->user_id !== $user->id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if (! $attempt->isActive() || $attempt->isExpired()) {
            return response()->json([
                'message'          => 'Tentative inactive.',
                'focus_violations' => (int) $attempt->focus_violations,
            ], 422);
        }

        $attempt->increment('focus_violations');

        return response()->json([
            'message'          => 'Violation enregistrée.',
            'focus_violations' => (int) $attempt->fresh()->focus_violations,
        ]);
    }

    // Déposer un fichier en réponse à une question de type "file"
    public function uploadAnswerFile(Request $request, ExamAttempt $attempt)
    {
        $user = $request->user();
        if ($attempt->user_id !== $user->id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if (! $attempt->isActive()) {
            return response()->json(['message' => 'Cette tentative est déjà terminée.'], 422);
        }

        if ($attempt->isExpired()) {
            $this->finalizeAttempt($attempt);
            return response()->json([
                'message' => 'Le temps est écoulé. Votre examen a été soumis automatiquement.',
                'expired' => true,
            ], 422);
        }

        $validated = $request->validate([
            'question_id' => 'required|integer',
            'file'        => 'required|file|max:20480|mimes:pdf,doc,docx,zip,rar,png,jpg,jpeg,xls,xlsx,ppt,pptx,txt',
        ]);

        $question = $attempt->questionById($validated['question_id']);

        if (! $question || ($question->type ?? 'mcq') !== 'file') {
            return response()->json(['message' => 'Question introuvable ou n\'acceptant pas de fichier.'], 422);
        }

        $files = $attempt->answer_files ?? [];

        // Remplacer l'ancien fichier éventuel
        if (! empty($files[(string) $question->id])) {
            Storage::disk('public')->delete($files[(string) $question->id]);
        }

        $uploaded = $request->file('file');
        $originalName = $uploaded->getClientOriginalName();
        $extension = strtolower($uploaded->getClientOriginalExtension() ?: $uploaded->extension());
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $safeBase = Str::slug($base) ?: 'reponse';
        $readableName = $safeBase.'-'.now()->format('Ymd-His').($extension ? '.'.$extension : '');

        $path = $uploaded->storeAs(
            "exam-answers/{$attempt->id}/{$question->id}",
            $readableName,
            'public'
        );

        $files[(string) $question->id] = $path;
        $attempt->update(['answer_files' => $files]);

        return response()->json([
            'message'           => 'Fichier déposé.',
            'question_id'       => $question->id,
            'file_name'         => $originalName,
            'remaining_seconds' => $attempt->remainingSeconds(),
        ]);
    }

    // Soumettre l'examen (ou auto-soumission à expiration)
    public function submit(Request $request, ExamAttempt $attempt)
    {
        $user = $request->user();
        if ($attempt->user_id !== $user->id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if (! $attempt->isActive()) {
            return response()->json(['message' => 'Cette tentative est déjà soumise.'], 422);
        }

        $validated = $request->validate([
            'answers'   => 'sometimes|array',
            'answers.*' => 'nullable|string',
        ]);

        // Petite marge de 10s pour la latence réseau
        $withinTime = now()->lessThanOrEqualTo($attempt->expires_at->addSeconds(10));

        if ($withinTime && array_key_exists('answers', $validated)) {
            $attempt->answers = ExamAttempt::mergeAnswerMaps(
                $attempt->answers ?? [],
                $validated['answers'] ?? []
            );
            $attempt->save();
        }

        $result = $this->finalizeAttempt($attempt->fresh(), $withinTime ? 'submitted' : 'expired');

        return response()->json($result);
    }

    // Résultat / copie d'une tentative (consultable même en attente ou en échec)
    public function result(Request $request, ExamAttempt $attempt)
    {
        $user = $request->user();
        if ($attempt->user_id !== $user->id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if ($attempt->isActive() && ! $attempt->isExpired()) {
            return response()->json(['message' => 'Cette tentative est encore en cours.'], 422);
        }

        if ($attempt->isExpired()) {
            $this->finalizeAttempt($attempt);
            $attempt->refresh();
        }

        $exam = $attempt->exam()->with('course')->first();
        $meta = $attempt->snapshotExamMeta();
        $certificate = Certificate::where('user_id', $user->id)
            ->where('course_id', $exam->course_id)
            ->first();

        return response()->json([
            'attempt' => [
                'id'           => $attempt->id,
                'score'        => $attempt->score,
                'passed'       => (bool) $attempt->passed,
                'status'       => $attempt->status,
                'started_at'   => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
            ],
            'exam' => [
                'id'         => $meta['id'] ?? $exam->id,
                'title'      => $meta['title'] ?? $exam->title,
                'pass_score' => $meta['pass_score'] ?? $exam->pass_score,
            ],
            'questions'   => $this->presentReviewQuestions($attempt),
            'certificate' => $certificate,
        ]);
    }

    // Télécharger un fichier déposé par l'apprenant sur sa propre tentative
    public function downloadAnswerFile(Request $request, ExamAttempt $attempt, $questionId)
    {
        if ($attempt->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if ($attempt->isActive() && ! $attempt->isExpired()) {
            return response()->json(['message' => 'Cette tentative est encore en cours.'], 422);
        }

        $files = $attempt->answerFilesByQuestionId();
        $path = $files[(string) $questionId] ?? null;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404, 'Fichier introuvable.');
        }

        return Storage::disk('public')->download($path, basename($path));
    }

    /**
     * Note la tentative, la clôture, délivre le certificat si réussite.
     * Si l'examen contient des questions ouvertes / fichiers, la tentative
     * passe en attente de correction manuelle par l'admin.
     */
    private function finalizeAttempt(ExamAttempt $attempt, string $status = 'expired'): array
    {
        // S'assurer qu'un snapshot existe (anciennes tentatives / reprise)
        if (empty($attempt->snapshot['questions'])) {
            $attempt->loadMissing('exam.questions');
            if ($attempt->exam) {
                $attempt->snapshot = ExamAttempt::buildSnapshot($attempt->exam);
                $attempt->saveQuietly();
            }
        }

        $exam = $attempt->exam()->with('course')->first();
        $meta = $attempt->snapshotExamMeta();
        $questions = $attempt->questionsForAttempt();
        $answers = $attempt->answersByQuestionId();
        $passScore = (int) ($meta['pass_score'] ?? $exam->pass_score);
        $examTitle = $meta['title'] ?? $exam->title;
        $certType = $meta['certificate_type'] ?? ($exam->certificate_type ?? 'certificat');

        $totalPoints = max(1, $questions->sum(fn ($q) => max(1, (int) $q->points)));
        $earned = 0;
        $correctCount = 0;
        $hasManual = false;

        foreach ($questions as $question) {
            if (($question->type ?? 'mcq') !== 'mcq') {
                $hasManual = true;
                continue;
            }
            $given = $answers[(string) $question->id] ?? null;
            if ($given !== null && (string) $given === (string) $question->correct_answer) {
                $earned += max(1, (int) $question->points);
                $correctCount++;
            }
        }

        $score = (int) round(($earned / $totalPoints) * 100);

        if ($hasManual) {
            // Score provisoire (QCM uniquement), résultat final après correction admin
            $attempt->update([
                'submitted_at' => now(),
                'score'        => $score,
                'passed'       => false,
                'status'       => 'pending_review',
            ]);

            Notify::send(
                $attempt->user_id,
                'exam_result',
                "Votre examen « {$examTitle} » a été soumis. Les questions ouvertes seront corrigées par l'équipe pédagogique : vous serez notifié du résultat final."
            );

            return [
                'attempt'        => $attempt->fresh(),
                'score'          => $score,
                'passed'         => false,
                'pending_review' => true,
                'correct'        => $correctCount,
                'total'          => $questions->count(),
                'certificate'    => null,
            ];
        }

        $passed = $score >= $passScore;

        $attempt->update([
            'submitted_at' => now(),
            'score'        => $score,
            'passed'       => $passed,
            'status'       => $status,
        ]);

        $certificate = null;
        if ($passed && $exam->course) {
            $design = CertificateDesign::normalize(
                $meta['certificate_design'] ?? $exam->certificate_design,
                $certType
            );
            $certificate = Certificate::firstOrCreate(
                ['user_id' => $attempt->user_id, 'course_id' => $exam->course_id],
                [
                    'type'            => $certType,
                    'design_snapshot' => $design,
                ]
            );
            if (! $certificate->type || empty($certificate->design_snapshot)) {
                $certificate->fill([
                    'type'            => $certificate->type ?: $certType,
                    'design_snapshot' => $certificate->design_snapshot ?: $design,
                ])->save();
            }

            $label = $certType === 'attestation' ? 'Votre attestation' : 'Votre certificat';
            Notify::send(
                $attempt->user_id,
                'exam_result',
                "Félicitations ! Vous avez réussi l'examen « {$examTitle} » avec {$score}%. {$label} est disponible sur votre tableau de bord."
            );
        } elseif (! $passed) {
            Notify::send(
                $attempt->user_id,
                'exam_result',
                "Examen « {$examTitle} » : score {$score}% (minimum requis : {$passScore}%). Vous pouvez retenter si des tentatives restent disponibles."
            );
        }

        return [
            'attempt'        => $attempt->fresh(),
            'score'          => $score,
            'passed'         => $passed,
            'pending_review' => false,
            'correct'        => $correctCount,
            'total'          => $questions->count(),
            'certificate'    => $certificate,
        ];
    }

    /**
     * Copie consultable : réponses de l'apprenant + correction QCM.
     * Utilise le snapshot figé au démarrage de la tentative.
     */
    private function presentReviewQuestions(ExamAttempt $attempt): array
    {
        $answers = $attempt->answersByQuestionId();
        $files = $attempt->answerFilesByQuestionId();
        $manualScores = $attempt->manualScoresByQuestionId();
        $questions = $attempt->questionsForAttempt();
        $byId = $questions->keyBy(fn ($q) => (string) $q->id);
        $order = $attempt->question_order ?: $questions->pluck('id')->all();

        return collect($order)
            ->map(function ($qid) use ($byId, $answers, $files, $manualScores) {
                $q = $byId->get((string) $qid);
                if (! $q) {
                    return null;
                }

                $type = $q->type ?? 'mcq';
                $given = $answers[(string) $q->id] ?? null;
                $filePath = $files[(string) $q->id] ?? null;
                $manualScore = $manualScores[(string) $q->id] ?? null;
                $isMcq = $type === 'mcq';

                return [
                    'id'             => $q->id,
                    'type'           => $type,
                    'question'       => $q->question,
                    'options'        => $isMcq ? ($q->options ?? []) : [],
                    'correct_answer' => $isMcq ? $q->correct_answer : null,
                    'points'         => max(1, (int) $q->points),
                    'given_answer'   => $given,
                    'is_correct'     => $isMcq
                        ? ($given !== null && (string) $given === (string) $q->correct_answer)
                        : null,
                    'file_name'      => $filePath ? basename((string) $filePath) : null,
                    'has_file'       => (bool) $filePath,
                    'manual_score'   => $manualScore,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Représentation d'une tentative active : questions du snapshot,
     * sans les bonnes réponses, options mélangées si configuré.
     */
    private function presentAttempt(ExamAttempt $attempt): array
    {
        if (empty($attempt->snapshot['questions'])) {
            $attempt->loadMissing('exam.questions');
            if ($attempt->exam) {
                $attempt->snapshot = ExamAttempt::buildSnapshot($attempt->exam);
                $attempt->saveQuietly();
            }
        }

        $meta = $attempt->snapshotExamMeta();
        $byId = $attempt->questionsForAttempt()->keyBy(fn ($q) => (string) $q->id);
        $files = $attempt->answerFilesByQuestionId();
        $shuffle = (bool) ($meta['shuffle_options'] ?? true);

        $questions = collect($attempt->question_order)
            ->map(function ($qid) use ($byId, $attempt, $files, $shuffle) {
                $q = $byId->get((string) $qid);
                if (! $q) {
                    return null;
                }

                $type = $q->type ?? 'mcq';
                $options = [];

                if ($type === 'mcq') {
                    $options = $q->options ?? [];
                    if ($shuffle) {
                        mt_srand($attempt->id * 100000 + $q->id);
                        $keys = array_keys($options);
                        shuffle($keys);
                        $options = array_values(array_map(fn ($k) => $options[$k], $keys));
                        mt_srand();
                    }
                }

                $filePath = $files[(string) $q->id] ?? null;

                return [
                    'id'        => $q->id,
                    'type'      => $type,
                    'question'  => $q->question,
                    'options'   => $options,
                    'points'    => $q->points,
                    'file_name' => $filePath ? basename($filePath) : null,
                ];
            })
            ->filter()
            ->values();

        return [
            'id'                => $attempt->id,
            'started_at'        => $attempt->started_at,
            'expires_at'        => $attempt->expires_at,
            'remaining_seconds' => $attempt->remainingSeconds(),
            'focus_violations'  => (int) ($attempt->focus_violations ?? 0),
            'answers'           => $attempt->answersByQuestionId() ?: (object) [],
            'questions'         => $questions,
        ];
    }
}
