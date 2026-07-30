<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('year_level');
            $table->timestamps();

            $table->unique(['program_id', 'year_level']);
        });

        Schema::create('program_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_year_id')->constrained()->cascadeOnDelete();
            $table->string('course_code', 20);
            $table->string('course_name');
            $table->timestamps();

            $table->unique(['program_year_id', 'course_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_courses');
        Schema::dropIfExists('program_years');
    }
};
