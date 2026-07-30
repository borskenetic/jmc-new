<?php

use App\Support\PatronOptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure student columns used by the school export/import sheet exist,
     * and normalize grade/year labels to the K–12 + college option set.
     */
    public function up(): void
    {
        if (! Schema::hasTable('students')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'lrn')) {
                $table->string('lrn', 32)->nullable()->after('student_id');
            }
            if (! Schema::hasColumn('students', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('midname');
            }
            if (! Schema::hasColumn('students', 'rfid')) {
                $table->string('rfid')->nullable()->unique()->after('qrcode');
            }
            if (! Schema::hasColumn('students', 'year')) {
                $table->string('year')->nullable()->after('course');
            }
            if (! Schema::hasColumn('students', 'educational_level')) {
                $table->string('educational_level', 32)->nullable()->after('sex');
            }
            if (! Schema::hasColumn('students', 'address')) {
                $table->text('address')->nullable();
            }
            if (! Schema::hasColumn('students', 'emergency_person')) {
                $table->string('emergency_person')->nullable();
            }
            if (! Schema::hasColumn('students', 'emergency_number')) {
                $table->string('emergency_number')->nullable();
            }
            if (! Schema::hasColumn('students', 'emergency_address')) {
                $table->text('emergency_address')->nullable();
            }
        });

        $this->normalizeStudentYears();
    }

    public function down(): void
    {
        // Data normalization is not reversed; column adds are left in place.
    }

    private function normalizeStudentYears(): void
    {
        DB::table('students')
            ->select(['id', 'year', 'educational_level'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $updates = [];
                    $normalizedYear = PatronOptions::normalizeYearLabel($row->year);

                    if ($normalizedYear !== null && $normalizedYear !== $row->year) {
                        $updates['year'] = $normalizedYear;
                    }

                    $level = PatronOptions::educationalLevelForYear($normalizedYear ?? $row->year);
                    if ($level !== null && ($row->educational_level === null || $row->educational_level === '')) {
                        $updates['educational_level'] = $level;
                    }

                    if ($updates !== []) {
                        DB::table('students')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }
};
