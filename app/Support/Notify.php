<?php

namespace App\Support;

use App\Mail\PlatformAlertMail;
use App\Models\AppNotification;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class Notify
{
    public static function send(
        User|int $user,
        string $type,
        string $message,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): AppNotification {
        $userModel = $user instanceof User ? $user : User::find($user);
        $userId = $userModel?->id ?? ($user instanceof User ? $user->id : (int) $user);

        $notification = AppNotification::create([
            'user_id' => $userId,
            'type'    => $type,
            'message' => $message,
        ]);

        if ($userModel?->email && PlatformSetting::get('emailNotifications', true)) {
            self::mailQuietly(
                $userModel->email,
                self::subjectFor($type),
                $message,
                $actionUrl,
                $actionLabel
            );
        }

        return $notification;
    }

    public static function toLearners(string $type, string $message): void
    {
        User::where('role', 'learner')
            ->whereNull('blocked_at')
            ->pluck('id')
            ->each(fn ($id) => self::send($id, $type, $message));
    }

    public static function toAdmins(string $type, string $message): void
    {
        User::where('role', 'admin')
            ->whereNull('blocked_at')
            ->pluck('id')
            ->each(function ($id) use ($type, $message) {
                $already = AppNotification::where('user_id', $id)
                    ->where('type', $type)
                    ->where('message', $message)
                    ->exists();

                if (! $already) {
                    self::send($id, $type, $message);
                }
            });
    }

    private static function subjectFor(string $type): string
    {
        $brand = PlatformSetting::get('platformName', config('app.name', 'Lonto Academy'));

        return match ($type) {
            'exam_result' => "Résultat d'examen — {$brand}",
            'exercise_corrected', 'exercise_due' => "Exercice — {$brand}",
            'enrollment', 'new_enrollment' => "Inscription — {$brand}",
            'certificate' => "Certificat — {$brand}",
            'weekly_report' => "Rapport hebdomadaire — {$brand}",
            default => "Notification — {$brand}",
        };
    }

    private static function mailQuietly(
        string $email,
        string $subject,
        string $message,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): void {
        try {
            Mail::to($email)->send(new PlatformAlertMail(
                $subject,
                $message,
                $actionUrl,
                $actionLabel
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
