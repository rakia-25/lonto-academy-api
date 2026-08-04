<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('short_description', 500)->nullable()->after('slug');
            $table->json('prerequisites')->nullable()->after('learning_objectives');
            $table->json('target_audience')->nullable()->after('prerequisites');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['short_description', 'prerequisites', 'target_audience']);
        });
    }
};
