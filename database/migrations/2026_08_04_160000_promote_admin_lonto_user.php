<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promotion one-shot de admin@lonto.com (déploiement Render sans Shell).
 * Peut rester : elle ne fait qu'un UPDATE idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'admin@lonto.com')
            ->update(['role' => 'admin']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'admin@lonto.com')
            ->update(['role' => 'learner']);
    }
};
