<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->string('type', 20)->default('mcq')->after('exam_id'); // mcq | open | file
            $table->json('options')->nullable()->change();
            $table->string('correct_answer')->nullable()->change();
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->json('answer_files')->nullable()->after('answers'); // question_id => file_path
            $table->json('manual_scores')->nullable()->after('score');  // question_id => points accordés
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropColumn(['answer_files', 'manual_scores']);
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
