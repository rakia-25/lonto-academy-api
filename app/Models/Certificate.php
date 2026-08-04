<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Certificate extends Model
{
    protected $fillable = [
        'user_id', 'course_id', 'type', 'design_snapshot', 'issued_at', 'verification_code',
    ];

    protected $casts = [
        'issued_at'       => 'datetime',
        'design_snapshot' => 'array',
    ];

    // Génère automatiquement le code de vérification
    protected static function booted(): void
    {
        static::creating(function ($certificate) {
            $certificate->verification_code = Str::uuid();
            $certificate->issued_at         = now();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
