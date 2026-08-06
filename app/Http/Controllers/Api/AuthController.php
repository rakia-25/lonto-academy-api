<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // Inscription
    public function register(RegisterRequest $request)
    {
        if (! PlatformSetting::get('allowRegistration', true)) {
            return response()->json([
                'message' => 'Les nouvelles inscriptions sont temporairement désactivées.',
            ], 403);
        }

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => 'learner',
        ]);

        // Une seule session active à la fois
        $user->tokens()->delete();
        $tokenResult = $user->createToken('auth_token');
        $tokenResult->accessToken->forceFill(['last_activity_at' => now()])->save();
        $token = $tokenResult->plainTextToken;

        Audit::log(
            'auth.register',
            "Inscription de {$user->name} ({$user->email})",
            $user,
            ['email' => $user->email],
            $user
        );

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ], 201);
    }

    // Connexion
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects.'],
            ]);
        }

        if ($user->blocked_at) {
            throw ValidationException::withMessages([
                'email' => ['Votre compte a été suspendu. Vous ne pouvez pas vous connecter. Contactez le support.'],
            ]);
        }

        // Invalide les sessions des autres appareils
        $user->tokens()->delete();
        $tokenResult = $user->createToken('auth_token');
        $tokenResult->accessToken->forceFill(['last_activity_at' => now()])->save();
        $token = $tokenResult->plainTextToken;

        Audit::log(
            'auth.login',
            "Connexion de {$user->name} ({$user->email})",
            $user,
            ['role' => $user->role],
            $user
        );

        return response()->json([
            'user'  => $user,
            'token' => $token,
            'session' => [
                'idle_timeout_minutes' => (int) config('session_security.idle_timeout_minutes', 20),
                'single_device' => true,
            ],
        ]);
    }

    /**
     * Maintient la session active (ex. pendant examen / formulaire).
     */
    public function heartbeat(Request $request)
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->forceFill(['last_activity_at' => now()])->save();
        }

        return response()->json([
            'ok' => true,
            'idle_timeout_minutes' => (int) config('session_security.idle_timeout_minutes', 20),
        ]);
    }

    // Profil connecté
    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    // Mise à jour du profil
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
        ]);

        $user->update($data);

        Audit::log(
            'profile.update',
            "Mise à jour du profil de {$user->name}",
            $user,
            ['email' => $user->email],
            $user
        );

        return response()->json($user->fresh());
    }

    public function uploadAvatar(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars/'.$user->id, 'public');
        $user->update(['avatar' => $path]);

        return response()->json($user->fresh());
    }

    public function deleteAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->update(['avatar' => null]);
        }

        return response()->json($user->fresh());
    }

    // Changement de mot de passe
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mot de passe actuel incorrect.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($data['password']),
        ]);

        Audit::log(
            'profile.password',
            "Changement de mot de passe de {$user->name}",
            $user,
            [],
            $user
        );

        return response()->json(['message' => 'Mot de passe mis à jour.']);
    }

    // Déconnexion
    public function logout(Request $request)
    {
        $user = $request->user();

        Audit::log(
            'auth.logout',
            "Déconnexion de {$user->name} ({$user->email})",
            $user,
            [],
            $user
        );

        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }
}
