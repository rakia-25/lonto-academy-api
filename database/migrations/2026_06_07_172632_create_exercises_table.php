<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('exercises', function (Blueprint $table) {
        $table->id();
        $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
        $table->string('title');
        $table->string('instructions_file'); // fichier consignes à télécharger
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
