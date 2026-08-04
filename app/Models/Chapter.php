<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chapter extends Model
{
    protected $fillable = ['course_id', 'title', 'video_path', 'duration', 'order'];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }

    public function quiz()
    {
        return $this->hasOne(Quiz::class);
    }


    public function exercises()
    {
        return $this->hasMany(\App\Models\Exercise::class);
    }

    // Le contrôleur et le front utilisent un exercice unique par chapitre.
    public function exercise()
    {
        return $this->hasOne(\App\Models\Exercise::class);
    }
}
