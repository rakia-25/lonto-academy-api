<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->longText('instructions')->nullable()->after('title');
            $table->string('instructions_file')->nullable()->change();
        });

        // Anciennes consignes texte stockées dans instructions_file (MySQL legacy)
        $rows = DB::table('exercises')->select('id', 'instructions_file')->get();
        foreach ($rows as $row) {
            $value = $row->instructions_file;
            if (! $value) {
                continue;
            }
            if (str_starts_with($value, 'exercise-files/')) {
                continue;
            }

            DB::table('exercises')->where('id', $row->id)->update([
                'instructions'      => '<p>'.e($value).'</p>',
                'instructions_file' => null,
            ]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('exercises')->select('id', 'instructions', 'instructions_file')->get();
        foreach ($rows as $row) {
            if ($row->instructions_file || ! $row->instructions) {
                continue;
            }
            DB::table('exercises')->where('id', $row->id)->update([
                'instructions_file' => trim(strip_tags($row->instructions)) ?: '',
            ]);
        }

        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn('instructions');
            $table->string('instructions_file')->nullable(false)->change();
        });
    }
};
