<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\ExerciseSubmission;
use App\Support\Audit;
use App\Support\CourseProgress;
use App\Support\Media;
use App\Support\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExerciseSubmissionController extends Controller
{
    public function index(Request $request)
    {
        $query = ExerciseSubmission::query()
            ->with([
                'user:id,name,email',
                'exercise:id,chapter_id,title',
                'exercise.chapter:id,course_id,title',
                'exercise.chapter.course:id,title,slug',
                'corrector:id,name',
            ])
            ->orderByDesc('submitted_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($courseId = $request->query('course_id')) {
            $query->whereHas('exercise.chapter', fn ($q) => $q->where('course_id', $courseId));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('exercise', function ($eq) use ($search) {
                    $eq->where('title', 'like', "%{$search}%");
                });
            });
        }

        $submissions = $query->paginate(20);

        $submissions->getCollection()->transform(function (ExerciseSubmission $submission) {
            return $this->present($submission);
        });

        $stats = [
            'total'     => ExerciseSubmission::count(),
            'pending'   => ExerciseSubmission::where('status', 'pending')->count(),
            'validated' => ExerciseSubmission::where('status', 'validated')->count(),
            'rejected'  => ExerciseSubmission::where('status', 'rejected')->count(),
        ];

        $courses = Course::query()
            ->whereHas('chapters.exercise.submissions')
            ->orderBy('title')
            ->get(['id', 'title']);

        return response()->json([
            'data'    => $submissions->items(),
            'meta'    => [
                'current_page' => $submissions->currentPage(),
                'last_page'    => $submissions->lastPage(),
                'per_page'     => $submissions->perPage(),
                'total'        => $submissions->total(),
            ],
            'stats'   => $stats,
            'courses' => $courses,
        ]);
    }

    public function show(ExerciseSubmission $submission)
    {
        $submission->load([
            'user:id,name,email',
            'exercise:id,chapter_id,title,instructions',
            'exercise.chapter:id,course_id,title',
            'exercise.chapter.course:id,title,slug',
            'corrector:id,name',
        ]);

        return response()->json(['submission' => $this->present($submission)]);
    }

    public function correct(Request $request, ExerciseSubmission $submission)
    {
        $data = $request->validate([
            'status'   => ['required', Rule::in(['validated', 'rejected', 'pending'])],
            'score'    => ['nullable', 'integer', 'min:0', 'max:100'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $newStatus = $data['status'];

        $submission->update([
            'status'       => $newStatus,
            'score'        => $data['score'] ?? null,
            'feedback'     => $data['feedback'] ?? null,
            'corrected_at' => $newStatus === 'pending' ? null : now(),
            'corrected_by' => $newStatus === 'pending' ? null : $request->user()->id,
        ]);

        $submission->load([
            'user:id,name,email',
            'exercise:id,chapter_id,title',
            'exercise.chapter:id,course_id,title',
            'exercise.chapter.course:id,title,slug',
            'corrector:id,name',
        ]);

        $exerciseTitle = $submission->exercise?->title ?? 'Exercice';
        $courseTitle = $submission->exercise?->chapter?->course?->title ?? 'le cours';

        if ($newStatus !== 'pending') {
            $label = $newStatus === 'validated' ? 'validé' : 'à retravailler';
            $scorePart = isset($data['score']) ? " (note : {$data['score']}/100)" : '';
            Notify::send(
                $submission->user_id,
                'exercise_corrected',
                "Votre exercice « {$exerciseTitle} » ({$courseTitle}) a été {$label}{$scorePart}."
            );
        }

        if ($newStatus === 'validated' && $submission->user && $submission->exercise?->chapter?->course) {
            CourseProgress::maybeIssueCertificate($submission->user, $submission->exercise->chapter->course);
        }

        $learnerName = $submission->user?->name ?? 'apprenant';
        Audit::log(
            'exercise.correct',
            "Correction de l'exercice « {$exerciseTitle} » pour {$learnerName} — {$newStatus}",
            $submission,
            [
                'status' => $newStatus,
                'score'  => $data['score'] ?? null,
                'user_id'=> $submission->user_id,
            ]
        );

        return response()->json([
            'message'    => 'Correction enregistrée.',
            'submission' => $this->present($submission),
        ]);
    }

    public function download(ExerciseSubmission $submission)
    {
        $this->assertFileExists($submission);

        return Media::download(
            $submission->file_path,
            basename($submission->file_path)
        );
    }

    public function preview(ExerciseSubmission $submission)
    {
        $this->assertFileExists($submission);

        $mime = Storage::disk('public')->mimeType($submission->file_path) ?: 'application/octet-stream';

        return Media::inline(
            $submission->file_path,
            basename($submission->file_path),
            $mime
        );
    }

    private function assertFileExists(ExerciseSubmission $submission): void
    {
        if (! $submission->file_path || ! Storage::disk('public')->exists($submission->file_path)) {
            abort(404, 'Fichier introuvable.');
        }
    }

    private function present(ExerciseSubmission $submission): array
    {
        $fileName = $submission->file_path ? basename($submission->file_path) : null;
        $originalName = $fileName
            ? (preg_replace('/-\d{8}-\d{6}(\.[^.]+)$/', '$1', $fileName) ?: $fileName)
            : null;

        return [
            'id'            => $submission->id,
            'user_id'       => $submission->user_id,
            'exercise_id'   => $submission->exercise_id,
            'file_path'     => $submission->file_path,
            'display_name'  => $fileName,
            'original_name' => $originalName,
            'submitted_at'  => $submission->submitted_at,
            'status'        => $submission->status ?? 'pending',
            'score'         => $submission->score,
            'feedback'      => $submission->feedback,
            'corrected_at'  => $submission->corrected_at,
            'user'          => $submission->user,
            'exercise'      => $submission->exercise,
            'corrector'     => $submission->corrector,
            'course'        => $submission->exercise?->chapter?->course,
            'chapter'       => $submission->exercise?->chapter
                ? [
                    'id'    => $submission->exercise->chapter->id,
                    'title' => $submission->exercise->chapter->title,
                ]
                : null,
        ];
    }
}
