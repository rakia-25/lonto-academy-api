<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseReview extends Model
{
    protected $fillable = [
        'user_id', 'course_id', 'rating', 'comment', 'is_published',
    ];

    protected $casts = [
        'rating'       => 'integer',
        'is_published' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public static function refreshCourseStats(Course $course): void
    {
        $agg = static::query()
            ->where('course_id', $course->id)
            ->where('is_published', true)
            ->selectRaw('COUNT(*) as cnt, AVG(rating) as avg_rating')
            ->first();

        $course->forceFill([
            'reviews_count' => (int) ($agg->cnt ?? 0),
            'avg_rating'    => $agg->cnt ? round((float) $agg->avg_rating, 2) : null,
        ])->save();
    }
}
