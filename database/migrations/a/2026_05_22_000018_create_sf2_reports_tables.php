<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sf2_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('school_id')->nullable();
            $table->string('school_name');
            $table->string('school_year', 16);
            $table->unsignedTinyInteger('report_month');
            $table->unsignedSmallInteger('report_year');
            $table->string('grade_level');
            $table->string('section');
            $table->json('school_days');
            $table->json('summary')->nullable();
            $table->string('teacher_name')->nullable();
            $table->string('school_head_name')->nullable();
            $table->timestamps();
        });

        Schema::create('sf2_report_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sf2_report_id')->constrained('sf2_reports')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('sex', 10);
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->text('remarks')->nullable();
            $table->json('absent_dates')->nullable();
            $table->json('tardy_dates')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sf2_report_students');
        Schema::dropIfExists('sf2_reports');
    }
};
