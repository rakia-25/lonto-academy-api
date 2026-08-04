<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    // Liste publique des cours publiés avec filtres
    public function index(Request $request)
    {
        $query = Course::with(['chapters.lessons'])
            ->withCount('enrollments')
            ->where('is_published', true);

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }

        $search = trim((string) ($request->search ?? $request->q ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('free')) {
            $query->where('price', '<=', 0);
        } elseif ($request->boolean('paid')) {
            $query->where('price', '>', 0);
        }

        if ($request->filled('price_min')) {
            $query->where('price', '>=', (float) $request->price_min);
        }
        if ($request->filled('price_max')) {
            $query->where('price', '<=', (float) $request->price_max);
        }

        $user = $request->user('sanctum');

        if ($request->boolean('mine') && $user) {
            $query->whereHas('enrollments', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->where(function ($q2) {
                        $q2->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            });
        }

        $courses = $query->orderBy('created_at', 'desc')->get();

        $enrolledIds = [];
        if ($user) {
            $enrolledIds = $user->enrollments()
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->pluck('course_id')
                ->all();
        }

        $courses->each(function ($course) use ($enrolledIds) {
            $course->chapters_count = $course->chapters->count();
            $course->lessons_count  = $course->chapters->sum(fn ($c) => $c->lessons->count());
            $course->enrolled = in_array($course->id, $enrolledIds, true);
            // Ne pas exposer les médias / contenus pédagogiques dans le catalogue
            $course->unsetRelation('chapters');
        });

        return response()->json($courses);
    }

    // Détail d'un cours
    public function show(string $slug)
    {
        $course = Course::with(['chapters.lessons.resources', 'chapters.quiz.questions', 'chapters.exercise'])
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        $enrolled = false;
        $user = request()->user('sanctum');
        if ($user) {
            $enrolled = $course->isEnrolled($user) || $user->role === 'admin';
        }

        if (! $enrolled) {
            $this->gateCourseContent($course);
        }

        return response()->json([
            'course'   => $course,
            'enrolled' => $user ? $course->isEnrolled($user) : false,
        ]);
    }

    // Cours achetés par l'apprenant connecté
    public function myLearning(Request $request)
    {
        $courses = Course::whereHas('enrollments', function ($q) use ($request) {
            $q->where('user_id', $request->user()->id)
              ->where(function ($q2) {
                  $q2->whereNull('expires_at')
                     ->orWhere('expires_at', '>', now());
              });
        })->with('chapters')->get();

        return response()->json($courses);
    }

    /**
     * Masque vidéos, ressources, questions QCM et consignes d'exercice
     * pour les visiteurs non inscrits.
     */
    private function gateCourseContent(Course $course): void
    {
        $course->chapters->each(function ($chapter) {
            $chapter->setAttribute('video_path', null);

            $chapter->lessons->each(function ($lesson) {
                $lesson->setAttribute('video_path', null);
                $lesson->setAttribute('introduction', null);
                $lesson->setRelation('resources', collect());
            });

            if ($chapter->relationLoaded('quiz') && $chapter->quiz) {
                $chapter->quiz->setRelation('questions', collect());
                $chapter->quiz->makeHidden(['pass_score']);
            }

            if ($chapter->relationLoaded('exercise') && $chapter->exercise) {
                $chapter->exercise->setAttribute('instructions', null);
                $chapter->exercise->setAttribute('instructions_file', null);
            }
        });
    }
}
