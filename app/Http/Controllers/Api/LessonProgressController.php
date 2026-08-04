<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LessonProgress;
use App\Models\Lesson;
use App\Models\ExerciseSubmission;
use App\Support\CourseProgress;
use Illuminate\Http\Request;

class LessonProgressController extends Controller
{
    // Marquer une leçon comme terminée ou mettre à jour la position
    public function update(Request $request, Lesson $lesson)
    {
        $request->validate([
            'completed'             => 'sometimes|boolean',
            'last_position_seconds' => 'sometimes|integer|min:0',
        ]);

        $lesson->load('chapter.course');
        $course = $lesson->chapter?->course;
        $user = $request->user();

        if (! $course || ! $course->isEnrolled($user)) {
            return response()->json(['message' => 'Vous devez être inscrit au cours.'], 403);
        }

        $lesson->loadMissing('chapter.exercise');

        // Une fois l'exercice du chapitre soumis, les leçons ne peuvent plus être marquées non terminées.
        if (
            $request->exists('completed')
            && ! $request->boolean('completed')
            && $lesson->chapter?->exercise
        ) {
            $exerciseSubmitted = ExerciseSubmission::where('user_id', $user->id)
                ->where('exercise_id', $lesson->chapter->exercise->id)
                ->whereNotNull('submitted_at')
                ->exists();

            if ($exerciseSubmitted) {
                return response()->json([
                    'message' => 'Impossible de marquer cette leçon comme non terminée : l\'exercice du chapitre a déjà été soumis. Supprimez d\'abord votre soumission si vous souhaitez modifier votre progression.',
                ], 422);
            }
        }

        $progress = LessonProgress::firstOrNew([
            'user_id'   => $user->id,
            'lesson_id' => $lesson->id,
        ]);

        if ($request->exists('completed')) {
            $progress->completed = $request->boolean('completed');
        } elseif (! $progress->exists) {
            $progress->completed = false;
        }

        if ($request->exists('last_position_seconds')) {
            $progress->last_position_seconds = $request->integer('last_position_seconds');
        } elseif (! $progress->exists) {
            $progress->last_position_seconds = 0;
        }

        $progress->save();

        $stats = CourseProgress::stats($user, $course);
        $certificate = CourseProgress::maybeIssueCertificate($user, $course);

        if ($request->exists('completed') && $request->boolean('completed')) {
            $chapter = $lesson->chapter;
            $chapter->loadMissing(['lessons', 'exercise']);
            $allDone = $chapter->lessons->every(function ($l) use ($user) {
                return LessonProgress::where('user_id', $user->id)
                    ->where('lesson_id', $l->id)
                    ->where('completed', true)
                    ->exists();
            });

            if ($allDone && $chapter->exercise) {
                $submitted = ExerciseSubmission::where('user_id', $user->id)
                    ->where('exercise_id', $chapter->exercise->id)
                    ->whereNotNull('submitted_at')
                    ->exists();

                if (! $submitted) {
                    $message = 'Chapitre « '.$chapter->title.' » terminé. Soumettez l\'exercice pour finaliser votre progression.';
                    $already = \App\Models\AppNotification::where('user_id', $user->id)
                        ->where('type', 'exercise_due')
                        ->where('message', $message)
                        ->exists();
                    if (! $already) {
                        \App\Support\Notify::send($user, 'exercise_due', $message);
                    }
                }
            }
        }

        return response()->json([
            'progress'    => $progress->fresh(),
            'stats'       => $stats,
            'certificate' => $certificate ? [
                'verification_code' => $certificate->verification_code,
                'type'              => $certificate->type,
                'issued_at'         => $certificate->issued_at,
            ] : null,
        ]);
    }

    // Récupérer la progression d'un cours pour l'apprenant
    public function courseProgress(Request $request, $courseId)
    {
        $user = $request->user();
        $course = \App\Models\Course::with(['chapters.lessons', 'chapters.exercise', 'chapters.quiz'])->findOrFail($courseId);

        if (! $course->isEnrolled($user)) {
            return response()->json(['message' => 'Vous devez être inscrit au cours.'], 403);
        }

        $lessons = LessonProgress::where('user_id', $user->id)
            ->whereHas('lesson.chapter', fn ($q) => $q->where('course_id', $courseId))
            ->get()
            ->keyBy('lesson_id');

        $exerciseIds = $course->chapters
            ->map(fn ($c) => $c->exercise?->id)
            ->filter()
            ->values();

        $exercises = ExerciseSubmission::where('user_id', $user->id)
            ->whereIn('exercise_id', $exerciseIds)
            ->get()
            ->keyBy('exercise_id');

        $quizIds = $course->chapters
            ->map(fn ($c) => $c->quiz?->id)
            ->filter()
            ->values();

        $quizzes = [];
        if ($quizIds->isNotEmpty()) {
            $attempts = \App\Models\QuizAttempt::where('user_id', $user->id)
                ->whereIn('quiz_id', $quizIds)
                ->get()
                ->groupBy('quiz_id');

            foreach ($quizIds as $qid) {
                $list = $attempts->get($qid, collect());
                $best = $list->sortByDesc('score')->first();
                $quizzes[$qid] = [
                    'quiz_id'    => $qid,
                    'passed'     => $list->contains(fn ($a) => $a->passed),
                    'best_score' => $best?->score,
                    'attempts'   => $list->count(),
                ];
            }
        }

        $stats = CourseProgress::stats($user, $course);

        return response()->json([
            'lessons'   => $lessons,
            'exercises' => $exercises,
            'quizzes'   => $quizzes,
            'stats'     => $stats,
        ]);
    }
}
