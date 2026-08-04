<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'slug', 'short_description', 'description', 'learning_objectives',
        'prerequisites', 'target_audience', 'category', 'level', 'price',
        'thumbnail', 'avg_rating', 'reviews_count', 'is_published',
    ];

    protected $casts = [
        'is_published'        => 'boolean',
        'price'               => 'decimal:2',
        'avg_rating'          => 'decimal:2',
        'reviews_count'       => 'integer',
        'learning_objectives' => 'array',
        'prerequisites'       => 'array',
        'target_audience'     => 'array',
    ];

    public function reviews()
    {
        return $this->hasMany(CourseReview::class);
    }

    public function chapters()
    {
        return $this->hasMany(Chapter::class)->orderBy('order');
    }

    // Vérifie si un utilisateur est inscrit à ce cours
    public function isEnrolled(User $user): bool
    {
        return $this->enrollments()
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })->exists();
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }
}
