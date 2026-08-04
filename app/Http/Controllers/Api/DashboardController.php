<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LessonProgress;
use App\Support\CourseProgress;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $enrollments = Enrollment::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with(['course.chapters.lessons', 'course.chapters.exercise', 'course.chapters.quiz'])
            ->orderByDesc('created_at')
            ->get();

        $courseIds = $enrollments->pluck('course_id')->filter()->values();

        $examsByCourse = Exam::whereIn('course_id', $courseIds)
            ->where('is_published', true)
            ->get()
            ->keyBy('course_id');

        $examIds = $examsByCourse->pluck('id');

        $passedExamCourseIds = ExamAttempt::where('user_id', $user->id)
            ->whereIn('exam_id', $examIds)
            ->where('passed', true)
            ->with('exam:id,course_id')
            ->get()
            ->pluck('exam.course_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $certsByCourse = Certificate::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->get()
            ->keyBy('course_id');

        $lastProgressByCourse = LessonProgress::where('user_id', $user->id)
            ->whereHas('lesson.chapter', fn ($q) => $q->whereIn('course_id', $courseIds))
            ->with(['lesson:id,title,chapter_id', 'lesson.chapter:id,course_id'])
            ->orderByDesc('updated_at')
            ->get()
            ->unique(fn ($p) => $p->lesson?->chapter?->course_id)
            ->keyBy(fn ($p) => $p->lesson?->chapter?->course_id);

        $courses = $enrollments->map(function ($enrollment) use ($user, $examsByCourse, $passedExamCourseIds, $certsByCourse, $lastProgressByCourse) {
            $course = $enrollment->course;
            if (! $course) {
                return null;
            }

            $stats = CourseProgress::stats($user, $course);
            $exam = $examsByCourse->get($course->id);
            $cert = $certsByCourse->get($course->id);
            $examPassed = in_array($course->id, $passedExamCourseIds, true);
            $last = $lastProgressByCourse->get($course->id);

            return [
                'id'                  => $course->id,
                'title'               => $course->title,
                'slug'                => $course->slug,
                'category'            => $course->category,
                'level'               => $course->level,
                'thumbnail'           => $course->thumbnail,
                'total'               => $stats['total_lessons'],
                'completed'           => $stats['completed_lessons'],
                'total_exercises'     => $stats['total_exercises'],
                'completed_exercises' => $stats['completed_exercises'],
                'total_quizzes'       => $stats['total_quizzes'],
                'completed_quizzes'   => $stats['completed_quizzes'],
                'progression'         => $stats['progression'],
                'has_exam'            => (bool) $exam,
                'exam_passed'         => $examPassed,
                'certificate_type'    => $exam?->certificate_type ?? ($cert?->type ?? null),
                'has_certificate'     => (bool) $cert,
                'certificate_code'    => $cert?->verification_code,
                'continue_lesson_id'  => $last?->lesson_id,
                'continue_lesson_title' => $last?->lesson?->title,
            ];
        })->filter()->values();

        $certificates = Certificate::where('user_id', $user->id)
            ->with('course:id,title,slug')
            ->latest('issued_at')
            ->get()
            ->map(function (Certificate $c) use ($examsByCourse) {
                $exam = $examsByCourse->get($c->course_id);
                $type = $c->type
                    ?? $exam?->certificate_type
                    ?? 'certificat';

                return [
                    'id'                => $c->id,
                    'type'              => $type === 'attestation' ? 'attestation' : 'certificat',
                    'issued_at'         => $c->issued_at,
                    'verification_code' => $c->verification_code,
                    'course_id'         => $c->course_id,
                    'course_title'      => $c->course?->title,
                    'course_slug'       => $c->course?->slug,
                ];
            });

        $inProgress = $courses->where('progression', '<', 100)->count();

        // Priorité : dernière leçon consultée (updated_at), sinon cours en cours le plus avancé
        $globalLast = $courseIds->isEmpty()
            ? null
            : LessonProgress::where('user_id', $user->id)
                ->whereHas('lesson.chapter', fn ($q) => $q->whereIn('course_id', $courseIds))
                ->with(['lesson:id,title,chapter_id', 'lesson.chapter:id,course_id'])
                ->orderByDesc('updated_at')
                ->first();

        $continueCourse = null;
        if ($globalLast?->lesson?->chapter?->course_id) {
            $base = $courses->firstWhere('id', $globalLast->lesson->chapter->course_id);
            if ($base && ($base['progression'] ?? 100) < 100) {
                $continueCourse = $base;
            }
        }
        if (! $continueCourse) {
            $continueCourse = $courses
                ->filter(fn ($c) => $c['progression'] > 0 && $c['progression'] < 100)
                ->sortByDesc('progression')
                ->first()
                ?? $courses->first(fn ($c) => $c['progression'] < 100);
        }

        return response()->json([
            'user'         => $user->only(['id', 'name', 'email', 'avatar']),
            'courses'      => $courses,
            'certificates' => $certificates,
            'continue'     => $continueCourse,
            'stats'        => [
                'total_cours'       => $enrollments->count(),
                'cours_en_cours'    => $inProgress,
                'cours_termines'    => $courses->where('progression', 100)->count(),
                'total_certificats' => $certificates->count(),
            ],
        ]);
    }
}
