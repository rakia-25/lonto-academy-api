<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\ExerciseSubmission;
use App\Support\CourseProgress;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExerciseSubmissionController extends Controller
{
    public function store(Request $request, Exercise $exercise)
    {
        $user = $request->user();
        $exercise->load('chapter.course.chapters.lessons', 'chapter.course.chapters.exercise');

        $course = $exercise->chapter?->course;
        if (! $course || ! $course->isEnrolled($user)) {
            return response()->json(['message' => 'Vous devez être inscrit au cours pour soumettre cet exercice.'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,zip,rar,png,jpg,jpeg,xls,xlsx,ppt,pptx',
        ]);

        $existing = ExerciseSubmission::where('user_id', $user->id)
            ->where('exercise_id', $exercise->id)
            ->first();

        if ($existing?->file_path) {
            Storage::disk('public')->delete($existing->file_path);
        }

        $uploaded = $request->file('file');
        $originalName = $uploaded->getClientOriginalName();
        $extension = strtolower($uploaded->getClientOriginalExtension() ?: $uploaded->extension());
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $safeBase = Str::slug($base) ?: 'exercice';
        // Nom lisible : mon-rapport-20260727-173245.pdf
        $readableName = $safeBase.'-'.now()->format('Ymd-His').($extension ? '.'.$extension : '');

        $path = $uploaded->storeAs(
            "exercise-submissions/{$exercise->id}/{$user->id}",
            $readableName,
            'public'
        );

        $submission = ExerciseSubmission::updateOrCreate(
            [
                'user_id'     => $user->id,
                'exercise_id' => $exercise->id,
            ],
            [
                'file_path'    => $path,
                'submitted_at' => now(),
                // Nouvelle soumission = à corriger à nouveau
                'status'       => 'pending',
                'score'        => null,
                'feedback'     => null,
                'corrected_at' => null,
                'corrected_by' => null,
            ]
        );

        // Expose le nom d'origine côté API (non persisté) pour l'affichage
        $submission->setAttribute('original_name', $originalName);
        $submission->setAttribute('display_name', $readableName);

        $stats = CourseProgress::stats($user, $course);
        $certificate = CourseProgress::maybeIssueCertificate($user, $course);

        return response()->json([
            'message'     => 'Exercice soumis avec succès.',
            'submission'  => $submission,
            'stats'       => $stats,
            'certificate' => $certificate ? [
                'verification_code' => $certificate->verification_code,
                'type'              => $certificate->type,
            ] : null,
        ], 201);
    }

    public function latest(Request $request, Exercise $exercise)
    {
        $submission = ExerciseSubmission::where('user_id', $request->user()->id)
            ->where('exercise_id', $exercise->id)
            ->latest('submitted_at')
            ->first();

        if ($submission) {
            $submission->setAttribute('display_name', basename($submission->file_path));
            $submission->setAttribute('original_name', $this->guessOriginalName($submission));
        }

        return response()->json(['submission' => $submission]);
    }

    public function download(Request $request, Exercise $exercise)
    {
        $submission = $this->findOwnedSubmission($request, $exercise);

        return Media::download(
            $submission->file_path,
            $this->displayName($submission)
        );
    }

    public function preview(Request $request, Exercise $exercise)
    {
        $submission = $this->findOwnedSubmission($request, $exercise);
        $mime = Storage::disk('public')->mimeType($submission->file_path) ?: 'application/octet-stream';

        return Media::inline(
            $submission->file_path,
            $this->displayName($submission),
            $mime
        );
    }

    public function destroy(Request $request, Exercise $exercise)
    {
        $user = $request->user();
        $exercise->load('chapter.course.chapters.lessons', 'chapter.course.chapters.exercise');

        $course = $exercise->chapter?->course;
        if (! $course || ! $course->isEnrolled($user)) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        $submission = ExerciseSubmission::where('user_id', $user->id)
            ->where('exercise_id', $exercise->id)
            ->first();

        if (! $submission) {
            return response()->json(['message' => 'Aucune soumission à supprimer.'], 404);
        }

        if ($submission->file_path) {
            Storage::disk('public')->delete($submission->file_path);
        }

        $submission->delete();

        $stats = CourseProgress::stats($user, $course);

        return response()->json([
            'message'    => 'Soumission supprimée.',
            'submission' => null,
            'stats'      => $stats,
        ]);
    }

    private function findOwnedSubmission(Request $request, Exercise $exercise): ExerciseSubmission
    {
        $submission = ExerciseSubmission::where('user_id', $request->user()->id)
            ->where('exercise_id', $exercise->id)
            ->firstOrFail();

        if (! $submission->file_path || ! Storage::disk('public')->exists($submission->file_path)) {
            abort(404, 'Fichier introuvable.');
        }

        return $submission;
    }

    private function displayName(ExerciseSubmission $submission): string
    {
        return basename($submission->file_path);
    }

    private function guessOriginalName(ExerciseSubmission $submission): string
    {
        $name = basename($submission->file_path);
        // Retire le suffixe -YYYYMMDD-HHMMSS avant l'extension si présent
        return preg_replace('/-\d{8}-\d{6}(\.[^.]+)$/', '$1', $name) ?: $name;
    }
}
