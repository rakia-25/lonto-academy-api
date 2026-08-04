<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->json('learning_objectives')->nullable()->after('description');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->text('introduction')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('learning_objectives');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('introduction');
        });
    }
};
