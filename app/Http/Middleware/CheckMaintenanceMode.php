<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    /** Routes API toujours accessibles en maintenance. */
    private array $except = [
        'api/settings',
        'api/login',
        'api/forgot-password',
        'api/reset-password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! PlatformSetting::get('maintenanceMode', false)) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        foreach ($this->except as $allowed) {
            if ($path === $allowed) {
                return $next($request);
            }
        }

        // Vérification publique des certificats
        if (preg_match('#^api/certificates/[^/]+/verify$#', $path)) {
            return $next($request);
        }

        $user = $request->user('sanctum');
        if ($user && $user->role === 'admin') {
            return $next($request);
        }

        return response()->json([
            'message'     => 'La plateforme est temporairement en maintenance.',
            'maintenance' => true,
        ], 503);
    }
}
