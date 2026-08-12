<?php

namespace Database\Seeders;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\AttendanceLog;
use App\Models\Student;
use App\Services\Sf2SchoolCalendar;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds a Grade 7 / St. Francis cohort plus June attendance IN logs for SF2 auto-generate testing.
 *
 * Run: php artisan db:seed --class=Sf2TestDataSeeder
 */
class Sf2TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $tz = config('sf2.timezone', 'Asia/Manila');
        $year = (int) now($tz)->format('Y');
        $month = (int) now($tz)->format('n');

        $schoolDays = app(Sf2SchoolCalendar::class)->schoolDaysInMonth($year, $month);

        if ($schoolDays === []) {
            $this->command?->warn('No school days in the current month — nothing to seed.');

            return;
        }

        $cohort = $this->cohortDefinition();
        $qrNumber = $this->nextQrSequence();
        $studentIds = [];

        foreach ($cohort as $row) {
            $fullName = trim($row['firstname'].' '.$row['lastname']);
            $row['normalized_name'] = NormalizeStudentNames::normalizeFullName($fullName);
            $row['role_id'] = null;

            $existing = Student::where('student_id', $row['student_id'])->first();
            if (! $existing) {
                $row['qrcode'] = 'S-'.str_pad((string) $qrNumber, 8, '0', STR_PAD_LEFT);
                $qrNumber++;
            }

            $student = Student::updateOrCreate(
                ['student_id' => $row['student_id']],
                $row
            );

            $studentIds[$row['student_id']] = $student->id;
        }

        AttendanceLog::query()
            ->whereIn('student_id', array_values($studentIds))
            ->whereBetween('scanned_at', [
                Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfDay(),
                Carbon::create($year, $month, 1, 0, 0, 0, $tz)->endOfMonth()->endOfDay(),
            ])
            ->delete();

        $patterns = $this->attendancePatterns($schoolDays, $tz);

        foreach ($patterns as $studentId => $dayTimes) {
            $dbId = $studentIds[$studentId] ?? null;
            if (! $dbId) {
                continue;
            }

            foreach ($dayTimes as $date => $time) {
                AttendanceLog::create([
                    'student_id' => $dbId,
                    'status' => 'IN',
                    'section' => null,
                    'scanned_at' => Carbon::parse($date.' '.$time, $tz),
                ]);
            }
        }

        $monthLabel = config('sf2.month_names')[$month] ?? (string) $month;

        $this->command?->info(sprintf(
            'SF2 test data ready: Grade 7 / St. Francis (%d learners), %s %d (%d school days with IN logs).',
            count($cohort),
            $monthLabel,
            $year,
            count($schoolDays)
        ));
        $this->command?->line('SF2 → Create → Grade 7, St. Francis, current month → Load from attendance logs.');
        $this->command?->line('Expected: Reyes & Santos mostly absent/tardy; Cruz, Garcia, Lopez on time; Mendoza absent mid-month.');
    }

    /** @return list<array<string, mixed>> */
    private function cohortDefinition(): array
    {
        return [
            [
                'student_id' => 'SF2-TEST-001',
                'firstname' => 'Antonio',
                'lastname' => 'Cruz',
                'midname' => 'M',
                'sex' => 'male',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111001',
                'birth_date' => '2012-04-10',
            ],
            [
                'student_id' => 'SF2-TEST-002',
                'firstname' => 'Rafael',
                'lastname' => 'Reyes',
                'midname' => 'D',
                'sex' => 'male',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111002',
                'birth_date' => '2012-08-22',
            ],
            [
                'student_id' => 'SF2-TEST-003',
                'firstname' => 'Diego',
                'lastname' => 'Lopez',
                'midname' => 'A',
                'sex' => 'male',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111003',
                'birth_date' => '2012-01-05',
            ],
            [
                'student_id' => 'SF2-TEST-004',
                'firstname' => 'Carmela',
                'lastname' => 'Garcia',
                'midname' => 'L',
                'sex' => 'female',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111004',
                'birth_date' => '2012-11-30',
            ],
            [
                'student_id' => 'SF2-TEST-005',
                'firstname' => 'Beatriz',
                'lastname' => 'Santos',
                'midname' => 'R',
                'sex' => 'female',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111005',
                'birth_date' => '2012-06-18',
            ],
            [
                'student_id' => 'SF2-TEST-006',
                'firstname' => 'Isabel',
                'lastname' => 'Mendoza',
                'midname' => 'C',
                'sex' => 'female',
                'section' => 'St. Francis',
                'educational_level' => 'high_school_junior',
                'course' => 'STEM',
                'year' => 'Grade 7',
                'mobile_number' => '09171111006',
                'birth_date' => '2012-09-09',
            ],
        ];
    }

    /**
     * Per-student scan times by date. Omitted dates = absent.
     * On time: 07:20. Tardy (after 07:40 cutoff with 10 min grace): 08:05.
     *
     * @param  list<string>  $schoolDays
     * @return array<string, array<string, string>>
     */
    private function attendancePatterns(array $schoolDays, string $tz): array
    {
        $onTime = '07:20:00';
        $tardy = '08:05:00';

        $patterns = [
            'SF2-TEST-001' => [], // perfect attendance
            'SF2-TEST-002' => [], // absent first 2 school days, tardy day 4
            'SF2-TEST-003' => [], // tardy every Friday
            'SF2-TEST-004' => [], // perfect attendance
            'SF2-TEST-005' => [], // absent every other school day
            'SF2-TEST-006' => [], // absent last 3 school days
        ];

        foreach ($schoolDays as $i => $date) {
            $dow = Carbon::parse($date, $tz)->dayOfWeekIso;

            $patterns['SF2-TEST-001'][$date] = $onTime;

            if ($i >= 2) {
                $patterns['SF2-TEST-002'][$date] = ($i === 3) ? $tardy : $onTime;
            }

            $patterns['SF2-TEST-003'][$date] = ($dow === 5) ? $tardy : $onTime;

            $patterns['SF2-TEST-004'][$date] = $onTime;

            if ($i % 2 === 0) {
                $patterns['SF2-TEST-005'][$date] = ($i % 4 === 0) ? $tardy : $onTime;
            }

            $lastIndex = count($schoolDays) - 1;
            if ($i < $lastIndex - 2) {
                $patterns['SF2-TEST-006'][$date] = $onTime;
            }
        }

        return $patterns;
    }

    private function nextQrSequence(): int
    {
        $last = Student::query()
            ->whereNotNull('qrcode')
            ->where('qrcode', 'like', 'S-%')
            ->orderByDesc('id')
            ->value('qrcode');

        if ($last && preg_match('/S-(\d+)/', $last, $matches)) {
            return (int) $matches[1] + 1;
        }

        return 1;
    }
}
