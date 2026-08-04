<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'avg_rating')) {
                $table->decimal('avg_rating', 3, 2)->nullable()->after('thumbnail');
            }
            if (! Schema::hasColumn('courses', 'reviews_count')) {
                $table->unsignedInteger('reviews_count')->default(0)->after('avg_rating');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'reviews_count')) {
                $table->dropColumn('reviews_count');
            }
            if (Schema::hasColumn('courses', 'avg_rating')) {
                $table->dropColumn('avg_rating');
            }
        });

        Schema::dropIfExists('course_reviews');
    }
};
