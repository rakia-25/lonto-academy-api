<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    protected $fillable = [
        'course_id', 'title', 'description', 'duration_minutes', 'pass_score',
        'max_attempts', 'shuffle_options', 'show_answers', 'certificate_type',
        'certificate_design', 'is_published',
    ];

    protected $casts = [
        'shuffle_options'    => 'boolean',
        'show_answers'       => 'boolean',
        'is_published'       => 'boolean',
        'certificate_design' => 'array',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function questions()
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('order');
    }

    public function attempts()
    {
        return $this->hasMany(ExamAttempt::class);
    }
}
