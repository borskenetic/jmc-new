<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_logs', 'scanned_at')) {
                $table->index('scanned_at', 'attendance_logs_scanned_at_index');
            }

            if (
                Schema::hasColumn('attendance_logs', 'status')
                && Schema::hasColumn('attendance_logs', 'scanned_at')
            ) {
                $table->index(['status', 'scanned_at'], 'attendance_logs_status_scanned_at_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('attendance_logs_scanned_at_index');
            $table->dropIndex('attendance_logs_status_scanned_at_index');
        });
    }
};
