<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Support\Audit;
use App\Support\CourseProgress;
use Illuminate\Http\Request;

class QuizAttemptController extends Controller
{
    public function store(Request $request, Quiz $quiz)
    {
        $user = $request->user();
        $quiz->load(['questions', 'chapter.course.chapters.lessons', 'chapter.course.chapters.quiz', 'chapter.course.chapters.exercise']);

        $course = $quiz->chapter?->course;
        if (! $course || ! $course->isEnrolled($user)) {
            return response()->json(['message' => 'Vous devez être inscrit au cours pour passer ce quiz.'], 403);
        }

        $validated = $request->validate([
            'answers'   => 'required|array',
            'answers.*' => 'nullable|string',
        ]);

        $questions = $quiz->questions;
        $total = $questions->count();

        if ($total === 0) {
            return response()->json(['message' => 'Ce quiz ne contient aucune question.'], 422);
        }

        $alreadyPassed = QuizAttempt::where('user_id', $user->id)
            ->where('quiz_id', $quiz->id)
            ->where('passed', true)
            ->exists();

        $correct = 0;
        $results = [];

        foreach ($questions as $question) {
            $given = $validated['answers'][(string) $question->id]
                ?? $validated['answers'][$question->id]
                ?? null;
            $isCorrect = $given !== null && $given === $question->correct_answer;
            if ($isCorrect) {
                $correct++;
            }
            $results[] = [
                'question_id'    => $question->id,
                'correct'        => $isCorrect,
                'correct_answer' => $question->correct_answer,
                'given_answer'   => $given,
            ];
        }

        $score = (int) round(($correct / $total) * 100);
        $passed = $score >= (int) ($quiz->pass_score ?? 70);

        // Déjà validé (≥ score min) : reprise en mode exercice, sans nouvel historique
        if ($alreadyPassed) {
            $stats = CourseProgress::stats($user, $course);

            return response()->json([
                'attempt'  => null,
                'practice' => true,
                'score'    => $score,
                'passed'   => $passed,
                'correct'  => $correct,
                'total'    => $total,
                'results'  => $results,
                'stats'    => $stats,
                'message'  => 'Mode exercice : ce QCM est déjà validé, aucune tentative n\'a été enregistrée.',
            ]);
        }

        $attempt = QuizAttempt::create([
            'user_id' => $user->id,
            'quiz_id' => $quiz->id,
            'score'   => $score,
            'passed'  => $passed,
        ]);

        Audit::log(
            'quiz.attempt',
            "Quiz « {$quiz->title} » — score {$score}%".($passed ? ' (réussi)' : ''),
            $attempt,
            [
                'quiz_id'   => $quiz->id,
                'course_id' => $course->id,
                'score'     => $score,
                'passed'    => $passed,
            ],
            $user
        );

        $stats = CourseProgress::stats($user, $course);
        $certificate = $passed ? CourseProgress::maybeIssueCertificate($user, $course) : null;

        return response()->json([
            'attempt'     => $attempt,
            'practice'    => false,
            'score'       => $score,
            'passed'      => $passed,
            'correct'     => $correct,
            'total'       => $total,
            'results'     => $results,
            'stats'       => $stats,
            'certificate' => $certificate ? [
                'verification_code' => $certificate->verification_code,
                'type'              => $certificate->type,
            ] : null,
        ], 201);
    }

    public function latest(Request $request, Quiz $quiz)
    {
        $attempt = QuizAttempt::where('user_id', $request->user()->id)
            ->where('quiz_id', $quiz->id)
            ->latest()
            ->first();

        return response()->json(['attempt' => $attempt]);
    }

    public function history(Request $request, Quiz $quiz)
    {
        $attempts = QuizAttempt::where('user_id', $request->user()->id)
            ->where('quiz_id', $quiz->id)
            ->latest()
            ->limit(20)
            ->get();

        $best = $attempts->sortByDesc('score')->first();
        $passedOnce = $attempts->contains(fn ($a) => $a->passed);

        return response()->json([
            'attempts'    => $attempts,
            'count'       => $attempts->count(),
            'best_score'  => $best?->score,
            'passed_once' => $passedOnce,
        ]);
    }
}
