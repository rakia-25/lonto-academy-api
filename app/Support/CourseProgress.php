<?php

namespace App\Support;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\ExerciseSubmission;
use App\Models\LessonProgress;
use App\Models\QuizAttempt;
use App\Models\User;

class CourseProgress
{
    /**
     * Calcule la progression d'un cours (leçons + QCM + exercices requis).
     *
     * @return array{total_lessons:int,completed_lessons:int,total_quizzes:int,completed_quizzes:int,total_exercises:int,completed_exercises:int,total_items:int,completed_items:int,progression:int,is_complete:bool}
     */
    public static function stats(User $user, Course $course): array
    {
        $course->loadMissing(['chapters.lessons', 'chapters.exercise', 'chapters.quiz']);

        $lessonIds = $course->chapters->flatMap(fn ($c) => $c->lessons->pluck('id'));
        $totalLessons = $lessonIds->count();

        $completedLessons = $totalLessons > 0
            ? LessonProgress::where('user_id', $user->id)
                ->where('completed', true)
                ->whereIn('lesson_id', $lessonIds)
                ->count()
            : 0;

        $quizIds = $course->chapters
            ->map(fn ($c) => $c->quiz?->id)
            ->filter()
            ->values();

        $totalQuizzes = $quizIds->count();

        $completedQuizzes = $totalQuizzes > 0
            ? QuizAttempt::where('user_id', $user->id)
                ->whereIn('quiz_id', $quizIds)
                ->where('passed', true)
                ->pluck('quiz_id')
                ->unique()
                ->count()
            : 0;

        $exerciseIds = $course->chapters
            ->map(fn ($c) => $c->exercise?->id)
            ->filter()
            ->values();

        $totalExercises = $exerciseIds->count();

        $completedExercises = $totalExercises > 0
            ? ExerciseSubmission::where('user_id', $user->id)
                ->whereIn('exercise_id', $exerciseIds)
                ->whereNotNull('submitted_at')
                ->get()
                ->pluck('exercise_id')
                ->unique()
                ->count()
            : 0;

        $totalItems = $totalLessons + $totalQuizzes + $totalExercises;
        $completedItems = $completedLessons + $completedQuizzes + $completedExercises;

        $progression = $totalItems > 0
            ? (int) round(($completedItems / $totalItems) * 100)
            : 0;

        $isComplete = $totalItems > 0
            && $completedLessons >= $totalLessons
            && $completedQuizzes >= $totalQuizzes
            && $completedExercises >= $totalExercises;

        return [
            'total_lessons'       => $totalLessons,
            'completed_lessons'   => $completedLessons,
            'total_quizzes'       => $totalQuizzes,
            'completed_quizzes'   => $completedQuizzes,
            'total_exercises'     => $totalExercises,
            'completed_exercises' => $completedExercises,
            'total_items'         => $totalItems,
            'completed_items'     => $completedItems,
            'progression'         => min(100, $progression),
            'is_complete'         => $isComplete,
        ];
    }

    /**
     * Vérifie si l'apprenant peut passer l'examen final :
     * leçons terminées + QCM réussis + exercices soumis + corrections positives.
     *
     * @return array{eligible:bool,reasons:string[]}
     */
    public static function examEligibility(User $user, Course $course): array
    {
        $stats = self::stats($user, $course);
        $reasons = [];

        if ($stats['total_lessons'] > 0 && $stats['completed_lessons'] < $stats['total_lessons']) {
            $reasons[] = 'Terminez toutes les leçons du cours ('
                .$stats['completed_lessons'].'/'.$stats['total_lessons'].').';
        }

        if ($stats['total_quizzes'] > 0 && $stats['completed_quizzes'] < $stats['total_quizzes']) {
            $reasons[] = 'Réussissez tous les QCM du cours (score minimum requis) ('
                .$stats['completed_quizzes'].'/'.$stats['total_quizzes'].').';
        }

        $exerciseIds = $course->chapters
            ->map(fn ($c) => $c->exercise?->id)
            ->filter()
            ->values();

        if ($exerciseIds->isNotEmpty()) {
            $submissions = ExerciseSubmission::where('user_id', $user->id)
                ->whereIn('exercise_id', $exerciseIds)
                ->whereNotNull('submitted_at')
                ->get()
                ->keyBy('exercise_id');

            $missing = $exerciseIds->count() - $submissions->count();
            if ($missing > 0) {
                $reasons[] = "Soumettez tous les exercices du cours ({$missing} manquant".($missing > 1 ? 's' : '').').';
            }

            $notValidated = $submissions->filter(fn ($s) => $s->status !== 'validated')->count();
            if ($notValidated > 0) {
                $reasons[] = "Attendez la validation de vos exercices par l'équipe pédagogique ({$notValidated} en attente ou à retravailler).";
            }
        }

        return [
            'eligible' => empty($reasons),
            'reasons'  => $reasons,
        ];
    }

    public static function maybeIssueCertificate(User $user, Course $course): ?Certificate
    {
        $stats = self::stats($user, $course);

        if (! $stats['is_complete']) {
            return null;
        }

        $existing = Certificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return Certificate::create([
            'user_id'   => $user->id,
            'course_id' => $course->id,
            'issued_at' => now(),
        ]);
    }
}
