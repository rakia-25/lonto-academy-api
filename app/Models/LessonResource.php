<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonResource extends Model
{
    protected $fillable = ['lesson_id', 'title', 'file_path'];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
