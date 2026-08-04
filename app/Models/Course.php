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
        'thumbnail', 'is_published',
    ];

    protected $casts = [
        'is_published'        => 'boolean',
        'price'               => 'decimal:2',
        'learning_objectives' => 'array',
        'prerequisites'       => 'array',
        'target_audience'     => 'array',
    ];

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
