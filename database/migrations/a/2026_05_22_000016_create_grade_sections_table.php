<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_sections', function (Blueprint $table) {
            $table->id();
            $table->string('grade_level', 64);
            $table->string('strand', 64)->default('');
            $table->string('section', 64);
            $table->timestamps();

            $table->unique(['grade_level', 'strand', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_sections');
    }
};
