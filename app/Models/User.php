<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'avatar', 'blocked_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'blocked_at'        => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = $frontend.'/reinitialiser-mot-de-passe?token='.urlencode($token).'&email='.urlencode($this->email);

        $this->notify(new ResetPasswordNotification($url));
    }

    public function enrollments()
    {
        return $this->hasMany(\App\Models\Enrollment::class);
    }

    public function certificates()
    {
        return $this->hasMany(\App\Models\Certificate::class);
    }

    public function payments()
    {
        return $this->hasMany(\App\Models\Payment::class);
    }
}
