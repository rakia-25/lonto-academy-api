<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercise_submissions', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('submitted_at');
            $table->unsignedTinyInteger('score')->nullable()->after('status');
            $table->text('feedback')->nullable()->after('score');
            $table->timestamp('corrected_at')->nullable()->after('feedback');
            $table->foreignId('corrected_by')->nullable()->after('corrected_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exercise_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn(['status', 'score', 'feedback', 'corrected_at']);
        });
    }
};
