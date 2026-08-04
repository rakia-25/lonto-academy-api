<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamQuestion extends Model
{
    protected $fillable = ['exam_id', 'type', 'question', 'options', 'correct_answer', 'points', 'order'];

    protected $casts = [
        'options' => 'array',
        'points'  => 'integer',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}
