<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExerciseSubmission extends Model
{
    protected $fillable = [
        'user_id',
        'exercise_id',
        'file_path',
        'submitted_at',
        'status',
        'score',
        'feedback',
        'corrected_at',
        'corrected_by',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'corrected_at' => 'datetime',
        'score'        => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function exercise()
    {
        return $this->belongsTo(Exercise::class);
    }

    public function corrector()
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
