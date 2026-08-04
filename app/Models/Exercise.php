<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    protected $fillable = ['chapter_id', 'title', 'instructions', 'instructions_file'];

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }

    public function submissions()
    {
        return $this->hasMany(ExerciseSubmission::class);
    }
}
