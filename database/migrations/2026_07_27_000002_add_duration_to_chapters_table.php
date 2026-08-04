<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->unsignedInteger('duration')->default(0)->after('video_path'); // en secondes
        });

        // Reprend la somme des durees des lecons existantes
        $sums = DB::table('lessons')
            ->select('chapter_id', DB::raw('SUM(duration) as total'))
            ->groupBy('chapter_id')
            ->get();

        foreach ($sums as $row) {
            DB::table('chapters')
                ->where('id', $row->chapter_id)
                ->update(['duration' => (int) $row->total]);
        }
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn('duration');
        });
    }
};
