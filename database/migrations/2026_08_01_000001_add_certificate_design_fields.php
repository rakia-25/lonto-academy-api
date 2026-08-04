<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (! Schema::hasColumn('exams', 'certificate_design')) {
                $table->json('certificate_design')->nullable()->after('certificate_type');
            }
        });

        Schema::table('certificates', function (Blueprint $table) {
            if (! Schema::hasColumn('certificates', 'type')) {
                $table->string('type', 20)->default('certificat')->after('course_id');
            }
            if (! Schema::hasColumn('certificates', 'design_snapshot')) {
                $table->json('design_snapshot')->nullable()->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (Schema::hasColumn('exams', 'certificate_design')) {
                $table->dropColumn('certificate_design');
            }
        });

        Schema::table('certificates', function (Blueprint $table) {
            if (Schema::hasColumn('certificates', 'design_snapshot')) {
                $table->dropColumn('design_snapshot');
            }
        });
    }
};
