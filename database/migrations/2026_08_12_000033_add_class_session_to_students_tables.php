<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'class_session')) {
                $table->string('class_session', 16)->nullable()->after('educational_level');
            }
        });

        if (Schema::hasTable('pending_students') && ! Schema::hasColumn('pending_students', 'class_session')) {
            Schema::table('pending_students', function (Blueprint $table) {
                $table->string('class_session', 16)->nullable()->after('educational_level');
            });
        }
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'class_session')) {
                $table->dropColumn('class_session');
            }
        });

        if (Schema::hasTable('pending_students') && Schema::hasColumn('pending_students', 'class_session')) {
            Schema::table('pending_students', function (Blueprint $table) {
                $table->dropColumn('class_session');
            });
        }
    }
};
