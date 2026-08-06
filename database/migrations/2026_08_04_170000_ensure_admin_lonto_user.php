<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Ancienne migration vide (cassait `php artisan migrate`).
 * Conservée comme no-op pour ne pas bloquer le batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
