<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseReview;
use Illuminate\Http\Request;

class CourseReviewController extends Controller
{
    public function index(string $slug)
    {
        $course = Course::where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        $reviews = CourseReview::with('user:id,name,avatar')
            ->where('course_id', $course->id)
            ->where('is_published', true)
            ->latest()
            ->get()
            ->map(fn (CourseReview $r) => $this->present($r));

        $mine = null;
        $user = request()->user('sanctum');
        if ($user) {
            $own = CourseReview::where('course_id', $course->id)
                ->where('user_id', $user->id)
                ->first();
            $mine = $own ? $this->present($own) : null;
        }

        return response()->json([
            'avg_rating'    => $course->avg_rating,
            'reviews_count' => (int) ($course->reviews_count ?? 0),
            'reviews'       => $reviews,
            'mine'          => $mine,
            'can_review'    => $user ? $course->isEnrolled($user) : false,
        ]);
    }

    public function store(Request $request, string $slug)
    {
        $user = $request->user();
        $course = Course::where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        if (! $course->isEnrolled($user)) {
            return response()->json([
                'message' => 'Vous devez être inscrit au cours pour laisser un avis.',
            ], 403);
        }

        $data = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $review = CourseReview::updateOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            [
                'rating'       => $data['rating'],
                'comment'      => $data['comment'] ?? null,
                'is_published' => true,
            ]
        );

        CourseReview::refreshCourseStats($course);

        return response()->json([
            'message' => 'Avis enregistré.',
            'review'  => $this->present($review->load('user:id,name,avatar')),
            'avg_rating'    => $course->fresh()->avg_rating,
            'reviews_count' => (int) $course->fresh()->reviews_count,
        ], 201);
    }

    private function present(CourseReview $review): array
    {
        return [
            'id'         => $review->id,
            'rating'     => $review->rating,
            'comment'    => $review->comment,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
            'user'       => [
                'id'     => $review->user?->id,
                'name'   => $review->user?->name,
                'avatar' => $review->user?->avatar,
            ],
        ];
    }
}
