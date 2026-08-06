<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('students') && ! Schema::hasColumn('students', 'student_signature')) {
            Schema::table('students', function (Blueprint $table) {
                $table->string('student_signature')->nullable()->after('emergency_address');
            });
        }

        if (Schema::hasTable('pending_students') && ! Schema::hasColumn('pending_students', 'student_signature')) {
            Schema::table('pending_students', function (Blueprint $table) {
                $table->string('student_signature')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('students') && Schema::hasColumn('students', 'student_signature')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropColumn('student_signature');
            });
        }

        if (Schema::hasTable('pending_students') && Schema::hasColumn('pending_students', 'student_signature')) {
            Schema::table('pending_students', function (Blueprint $table) {
                $table->dropColumn('student_signature');
            });
        }
    }
};
