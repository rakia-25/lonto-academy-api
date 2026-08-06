<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->blocked_at) {
            // Invalide toute session encore active
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Votre compte a été suspendu. Contactez le support.',
                'code' => 'account_suspended',
            ], 403);
        }

        return $next($request);
    }
}
