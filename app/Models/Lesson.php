<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $fillable = ['chapter_id', 'title', 'introduction', 'video_path', 'duration', 'order'];

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }

    public function resources()
    {
        return $this->hasMany(LessonResource::class);
    }
}
