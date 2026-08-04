<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Support\CertificateDesign;
use App\Support\CertificatePdf;
use App\Support\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class ExamController extends Controller
{
    // Examen d'un cours (avec questions et bonnes réponses, côté admin)
    public function show(Course $course)
    {
        $exam = Exam::where('course_id', $course->id)
            ->with('questions')
            ->withCount('attempts')
            ->first();

        return response()->json([
            'course' => ['id' => $course->id, 'title' => $course->title],
            'exam'   => $exam,
            'certificate_templates' => CertificateDesign::templatesMeta(),
            'certificate_defaults'  => [
                'certificat'  => CertificateDesign::defaults('certificat'),
                'attestation' => CertificateDesign::defaults('attestation'),
            ],
        ]);
    }

    // Créer ou mettre à jour l'examen d'un cours
    public function store(Request $request, Course $course)
    {
        $data = $this->validateExam($request);
        $data = $this->normalizeCertificateFields($data);

        $exam = Exam::updateOrCreate(
            ['course_id' => $course->id],
            $data
        );

        return response()->json([
            'message' => 'Examen enregistré.',
            'exam'    => $exam->load('questions'),
        ], 201);
    }

    public function update(Request $request, Exam $exam)
    {
        $data = $this->validateExam($request);
        $data = $this->normalizeCertificateFields($data);
        $exam->update($data);

        return response()->json([
            'message' => 'Examen mis à jour.',
            'exam'    => $exam->load('questions'),
        ]);
    }

    /** Aperçu PDF du certificat/attestation avec les réglages courants. */
    public function previewCertificate(Request $request, Exam $exam)
    {
        $exam->loadMissing('course');

        $type = $request->input('certificate_type', $exam->certificate_type ?? 'certificat');
        $type = $type === 'attestation' ? 'attestation' : 'certificat';

        $designInput = $request->input('certificate_design', $exam->certificate_design);
        if (is_string($designInput)) {
            $designInput = json_decode($designInput, true);
        }
        $design = CertificateDesign::normalize(is_array($designInput) ? $designInput : null, $type);

        $fakeCertificate = new Certificate([
            'type'              => $type,
            'issued_at'         => now(),
            'verification_code' => (string) Str::uuid(),
        ]);

        $fakeLearner = (object) [
            'name' => $request->user()?->name ?: 'Jean Dupont',
        ];

        [$pdf] = CertificatePdf::make(
            $fakeCertificate,
            $fakeLearner,
            $exam->course,
            $design
        );

        $filename = ($type === 'attestation' ? 'apercu-attestation' : 'apercu-certificat').'.pdf';

        return $pdf->stream($filename);
    }

    /** Liste des modèles disponibles (pour l'UI admin). */
    public function certificateTemplates()
    {
        return response()->json([
            'templates' => CertificateDesign::templatesMeta(),
            'defaults'  => [
                'certificat'  => CertificateDesign::defaults('certificat'),
                'attestation' => CertificateDesign::defaults('attestation'),
            ],
        ]);
    }

    public function destroy(Exam $exam)
    {
        $exam->delete();
        return response()->json(['message' => 'Examen supprimé.']);
    }

    public function togglePublish(Exam $exam)
    {
        $exam->loadCount('questions');

        if (! $exam->is_published && $exam->questions_count === 0) {
            return response()->json(['message' => 'Ajoutez au moins une question avant de publier l\'examen.'], 422);
        }

        $exam->update(['is_published' => ! $exam->is_published]);

        return response()->json([
            'message' => $exam->is_published ? 'Examen publié.' : 'Examen dépublié.',
            'exam'    => $exam,
        ]);
    }

    // Remplace l'ensemble des questions (éditeur en bloc côté front)
    public function syncQuestions(Request $request, Exam $exam)
    {
        $validated = $request->validate([
            'questions'                    => 'required|array|min:1',
            'questions.*.type'             => ['required', Rule::in(['mcq', 'open', 'file'])],
            'questions.*.question'         => 'required|string',
            'questions.*.options'          => 'nullable|array',
            'questions.*.options.*'        => 'nullable|string',
            'questions.*.correct_answer'   => 'nullable|string',
            'questions.*.points'           => 'nullable|integer|min:1|max:20',
        ]);

        foreach ($validated['questions'] as $i => $q) {
            if ($q['type'] !== 'mcq') {
                continue;
            }
            $options = array_values(array_filter($q['options'] ?? [], fn ($o) => trim((string) $o) !== ''));
            if (count($options) < 2) {
                return response()->json([
                    'message' => 'Question '.($i + 1).' (QCM) : au moins 2 options sont requises.',
                ], 422);
            }
            if (empty($q['correct_answer']) || ! in_array($q['correct_answer'], $options, true)) {
                return response()->json([
                    'message' => 'Question '.($i + 1).' (QCM) : la bonne réponse doit faire partie des options.',
                ], 422);
            }
        }

        $exam->questions()->delete();

        foreach ($validated['questions'] as $i => $q) {
            $isMcq = $q['type'] === 'mcq';
            ExamQuestion::create([
                'exam_id'        => $exam->id,
                'type'           => $q['type'],
                'question'       => $q['question'],
                'options'        => $isMcq
                    ? array_values(array_filter($q['options'] ?? [], fn ($o) => trim((string) $o) !== ''))
                    : null,
                'correct_answer' => $isMcq ? $q['correct_answer'] : null,
                'points'         => $q['points'] ?? 1,
                'order'          => $i,
            ]);
        }

        return response()->json([
            'message' => 'Questions enregistrées.',
            'exam'    => $exam->load('questions'),
        ]);
    }

    // Détail d'une tentative (pour la correction manuelle)
    public function attemptDetail(ExamAttempt $attempt)
    {
        $attempt->load('user:id,name,email', 'exam');

        if (empty($attempt->snapshot['questions']) && $attempt->exam) {
            $attempt->exam->loadMissing('questions');
            $attempt->snapshot = ExamAttempt::buildSnapshot($attempt->exam);
            $attempt->saveQuietly();
        }

        $meta = $attempt->snapshotExamMeta();
        $answers = $attempt->answersByQuestionId();
        $files = $attempt->answerFilesByQuestionId();
        $manualScores = $attempt->manualScoresByQuestionId();
        $questionsCol = $attempt->questionsForAttempt();
        $byId = $questionsCol->keyBy(fn ($q) => (string) $q->id);
        $order = $attempt->question_order ?: $questionsCol->pluck('id')->all();

        $questions = collect($order)
            ->map(function ($qid) use ($byId, $answers, $files, $manualScores) {
                $q = $byId->get((string) $qid);
                if (! $q) {
                    return null;
                }

                $given = $answers[(string) $q->id] ?? null;
                $type = $q->type ?? 'mcq';
                $filePath = $files[(string) $q->id] ?? null;

                return [
                    'id'             => $q->id,
                    'type'           => $type,
                    'question'       => $q->question,
                    'options'        => $type === 'mcq' ? ($q->options ?? []) : [],
                    'correct_answer' => $type === 'mcq' ? $q->correct_answer : null,
                    'points'         => max(1, (int) $q->points),
                    'given_answer'   => $given,
                    'is_correct'     => $type === 'mcq'
                        ? ($given !== null && (string) $given === (string) $q->correct_answer)
                        : null,
                    'file_name'      => $filePath ? basename($filePath) : null,
                    'has_file'       => (bool) $filePath,
                    'manual_score'   => $manualScores[(string) $q->id] ?? null,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'attempt' => [
                'id'               => $attempt->id,
                'user'             => $attempt->user,
                'score'            => $attempt->score,
                'passed'           => $attempt->passed,
                'status'           => $attempt->status,
                'focus_violations' => (int) ($attempt->focus_violations ?? 0),
                'started_at'       => $attempt->started_at,
                'submitted_at'     => $attempt->submitted_at,
            ],
            'exam' => [
                'id'         => $meta['id'] ?? $attempt->exam_id,
                'title'      => $meta['title'] ?? $attempt->exam?->title,
                'pass_score' => $meta['pass_score'] ?? $attempt->exam?->pass_score,
            ],
            'questions' => $questions,
            'frozen'    => ! empty($attempt->snapshot['questions']),
        ]);
    }

    // Noter les questions ouvertes / fichiers, calculer le résultat final
    public function gradeAttempt(Request $request, ExamAttempt $attempt)
    {
        if ($attempt->status === 'in_progress') {
            return response()->json(['message' => 'Cette tentative est encore en cours.'], 422);
        }

        $validated = $request->validate([
            'scores'   => 'required|array',
            'scores.*' => 'required|numeric|min:0',
        ]);

        $attempt->load('exam.course', 'exam.questions');

        if (empty($attempt->snapshot['questions']) && $attempt->exam) {
            $attempt->snapshot = ExamAttempt::buildSnapshot($attempt->exam);
            $attempt->saveQuietly();
        }

        $exam = $attempt->exam;
        $meta = $attempt->snapshotExamMeta();
        $questions = $attempt->questionsForAttempt();
        $answers = $attempt->answersByQuestionId();
        $passScore = (int) ($meta['pass_score'] ?? $exam->pass_score);
        $examTitle = $meta['title'] ?? $exam->title;
        $certType = $meta['certificate_type'] ?? ($exam->certificate_type ?? 'certificat');

        $totalPoints = max(1, $questions->sum(fn ($q) => max(1, (int) $q->points)));
        $earned = 0;
        $manualScores = [];

        foreach ($questions as $q) {
            $max = max(1, (int) $q->points);
            if (($q->type ?? 'mcq') === 'mcq') {
                $given = $answers[(string) $q->id] ?? null;
                if ($given !== null && (string) $given === (string) $q->correct_answer) {
                    $earned += $max;
                }
            } else {
                $awarded = $validated['scores'][(string) $q->id] ?? $validated['scores'][$q->id] ?? 0;
                $awarded = min($max, max(0, (float) $awarded));
                $manualScores[(string) $q->id] = $awarded;
                $earned += $awarded;
            }
        }

        $score = (int) round(($earned / $totalPoints) * 100);
        $passed = $score >= $passScore;

        $attempt->update([
            'score'         => $score,
            'passed'        => $passed,
            'manual_scores' => $manualScores,
            'status'        => 'graded',
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
                "Félicitations ! Votre examen « {$examTitle} » a été corrigé : {$score}%, examen réussi. {$label} est disponible sur votre tableau de bord."
            );
        } else {
            Notify::send(
                $attempt->user_id,
                'exam_result',
                "Votre examen « {$examTitle} » a été corrigé : {$score}% (minimum requis : {$passScore}%). Vous pouvez retenter si des tentatives restent disponibles."
            );
        }

        return response()->json([
            'message'     => 'Correction enregistrée.',
            'attempt'     => $attempt->fresh(),
            'score'       => $score,
            'passed'      => $passed,
            'certificate' => $certificate,
        ]);
    }

    // Télécharger le fichier déposé pour une question
    public function downloadAnswerFile(ExamAttempt $attempt, $questionId)
    {
        $files = $attempt->answerFilesByQuestionId();
        $path = $files[(string) $questionId] ?? null;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404, 'Fichier introuvable.');
        }

        return Storage::disk('public')->download($path, basename($path));
    }

    // Résultats des apprenants
    public function results(Exam $exam)
    {
        $attempts = $exam->attempts()
            ->with('user:id,name,email')
            ->where('status', '!=', 'in_progress')
            ->orderByDesc('submitted_at')
            ->paginate(30);

        $stats = [
            'total_attempts' => $exam->attempts()->where('status', '!=', 'in_progress')->count(),
            'passed'         => $exam->attempts()->where('passed', true)->count(),
            'average_score'  => (int) round($exam->attempts()->where('status', '!=', 'in_progress')->avg('score') ?? 0),
            'in_progress'    => $exam->attempts()->where('status', 'in_progress')->count(),
            'pending_review' => $exam->attempts()->where('status', 'pending_review')->count(),
        ];

        return response()->json([
            'attempts' => $attempts,
            'stats'    => $stats,
        ]);
    }

    private function validateExam(Request $request): array
    {
        return $request->validate([
            'title'                               => 'required|string|max:255',
            'description'                         => 'nullable|string|max:5000',
            'duration_minutes'                    => 'required|integer|min:5|max:480',
            'pass_score'                          => 'required|integer|min:1|max:100',
            'max_attempts'                        => 'nullable|integer|min:1|max:20',
            'shuffle_options'                     => 'sometimes|boolean',
            'show_answers'                        => 'sometimes|boolean',
            'certificate_type'                    => ['sometimes', Rule::in(['certificat', 'attestation'])],
            'certificate_design'                  => 'sometimes|nullable|array',
            'certificate_design.template'         => ['sometimes', Rule::in(CertificateDesign::TEMPLATES)],
            'certificate_design.brand_name'       => 'sometimes|nullable|string|max:120',
            'certificate_design.title'            => 'sometimes|nullable|string|max:255',
            'certificate_design.subtitle'         => 'sometimes|nullable|string|max:255',
            'certificate_design.awarded_label'    => 'sometimes|nullable|string|max:120',
            'certificate_design.course_label'     => 'sometimes|nullable|string|max:255',
            'certificate_design.footer'           => 'sometimes|nullable|string|max:255',
            'certificate_design.accent_color'     => 'sometimes|nullable|string|max:20',
            'certificate_design.text_color'       => 'sometimes|nullable|string|max:20',
            'certificate_design.signer_name'      => 'sometimes|nullable|string|max:120',
            'certificate_design.signer_title'     => 'sometimes|nullable|string|max:120',
            'certificate_design.show_date'        => 'sometimes|boolean',
            'certificate_design.show_verification_code' => 'sometimes|boolean',
            'certificate_design.show_signer'      => 'sometimes|boolean',
        ]);
    }

    private function normalizeCertificateFields(array $data): array
    {
        $type = $data['certificate_type'] ?? 'certificat';
        if (array_key_exists('certificate_design', $data)) {
            $data['certificate_design'] = CertificateDesign::normalize(
                is_array($data['certificate_design'] ?? null) ? $data['certificate_design'] : null,
                $type
            );
        }

        return $data;
    }
}
