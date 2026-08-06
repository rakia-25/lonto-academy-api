<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureSessionFresh
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $idleMinutes = max(1, (int) config('session_security.idle_timeout_minutes', 20));
        $lastActivity = $this->asCarbon($token->last_activity_at)
            ?? $this->asCarbon($token->created_at);

        if ($lastActivity && $lastActivity->lt(now()->subMinutes($idleMinutes))) {
            $token->delete();

            return response()->json([
                'message' => 'Session expirée pour cause d\'inactivité. Veuillez vous reconnecter.',
                'code' => 'session_idle',
            ], 401);
        }

        $touchSeconds = max(30, (int) config('session_security.activity_touch_seconds', 60));
        $currentActivity = $this->asCarbon($token->last_activity_at);

        if (! $currentActivity || $currentActivity->lt(now()->subSeconds($touchSeconds))) {
            $token->forceFill(['last_activity_at' => now()])->save();
        }

        return $next($request);
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
