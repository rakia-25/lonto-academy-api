<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Crée un compte admin dédié (sans toucher aux comptes learners existants).
 */
return new class extends Migration
{
    public function up(): void
    {
        $email = 'admin@lonto.com';
        $now = now();

        $existing = DB::table('users')->where('email', $email)->first();

        if ($existing) {
            DB::table('users')->where('email', $email)->update([
                'name' => 'Admin Lonto',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'blocked_at' => null,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('users')->insert([
            'name' => 'Admin Lonto',
            'email' => $email,
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'admin@lonto.com')
            ->where('role', 'admin')
            ->delete();
    }
};
