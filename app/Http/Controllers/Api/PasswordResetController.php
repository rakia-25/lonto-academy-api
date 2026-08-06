<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function forgot(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();

        // Compte suspendu : pas de lien, réponse générique (pas de fuite d'info)
        if ($user && $user->blocked_at) {
            return response()->json([
                'message' => 'Si un compte existe avec cette adresse, un e-mail de réinitialisation a été envoyé.',
                'status' => 'accepted',
            ]);
        }

        $status = Password::broker()->sendResetLink(
            $request->only('email')
        );

        // Toujours la même réponse pour ne pas révéler si l'e-mail existe
        return response()->json([
            'message' => 'Si un compte existe avec cette adresse, un e-mail de réinitialisation a été envoyé.',
            'status'  => $status === Password::RESET_LINK_SENT ? 'sent' : 'accepted',
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $existing = User::where('email', $request->email)->first();
        if ($existing?->blocked_at) {
            throw ValidationException::withMessages([
                'email' => ['Ce compte est suspendu. La réinitialisation est impossible.'],
            ]);
        }

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                if ($user->blocked_at) {
                    throw ValidationException::withMessages([
                        'email' => ['Ce compte est suspendu. La réinitialisation est impossible.'],
                    ]);
                }

                $user->forceFill([
                    'password'       => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                Audit::log(
                    'auth.password_reset',
                    "Réinitialisation du mot de passe de {$user->name} ({$user->email})",
                    $user,
                    [],
                    $user
                );
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Mot de passe réinitialisé. Vous pouvez vous connecter.',
        ]);
    }
}
