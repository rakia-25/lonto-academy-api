<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class Audit
{
    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        User|int|null $user = null,
    ): ?ActivityLog {
        try {
            $userId = null;
            if ($user instanceof User) {
                $userId = $user->id;
            } elseif (is_int($user)) {
                $userId = $user;
            } elseif (Auth::check()) {
                $userId = Auth::id();
            }

            $ua = Request::userAgent();
            if ($ua !== null && strlen($ua) > 500) {
                $ua = substr($ua, 0, 500);
            }

            return ActivityLog::create([
                'user_id'      => $userId,
                'action'       => $action,
                'description'  => $description,
                'subject_type' => $subject ? $subject->getMorphClass() : null,
                'subject_id'   => $subject?->getKey(),
                'properties'   => $properties ?: null,
                'ip_address'   => Request::ip(),
                'user_agent'   => $ua,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
