<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

/**
 * Crée ou réinitialise le compte admin (Render sans Shell).
 * Email : admin@lonto.com — à changer après connexion.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@lonto.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'blocked_at' => null,
            ]
        );
    }

    public function down(): void
    {
        // Ne supprime pas le compte : évite une perte de données en rollback.
    }
};
