<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Models\Visitor;
use App\Models\VisitorLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceSessionService
{
    public const TZ = 'Asia/Manila';

    public function isInStatus(?string $status): bool
    {
        return $status !== null && strtolower(trim((string) $status)) === 'in';
    }

    public function isOutStatus(?string $status): bool
    {
        return $status !== null && strtolower(trim((string) $status)) === 'out';
    }

    public function closeStaleOpenInForStudent(Student $student): bool
    {
        $last = AttendanceLog::query()
            ->where('student_id', $student->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        if (! $last || ! $this->isInStatus($last->status)) {
            return false;
        }

        $inDayStart = $last->scanned_at->copy()->startOfDay();
        $todayStart = Carbon::today();

        if ($inDayStart->greaterThanOrEqualTo($todayStart)) {
            return false;
        }

        $outAt = $last->scanned_at->copy()->endOfDay();

        AttendanceLog::create([
            'student_id' => $student->id,
            'status' => 'OUT',
            'is_late' => false,
            'scanned_at' => $outAt,
        ]);

        return true;
    }

    public function closeStaleOpenInForVisitor(Visitor $visitor): bool
    {
        $last = VisitorLog::query()
            ->where('visitor_id', $visitor->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        if (! $last || ! $this->isInStatus($last->status)) {
            return false;
        }

        $inDayStart = $last->scanned_at->copy()->startOfDay();
        $todayStart = Carbon::today();

        if ($inDayStart->greaterThanOrEqualTo($todayStart)) {
            return false;
        }

        $outAt = $last->scanned_at->copy()->endOfDay();

        VisitorLog::create([
            'visitor_id' => $visitor->id,
            'status' => 'OUT',
            'scanned_at' => $outAt,
        ]);

        return true;
    }

    public function closeAllStaleOpenIns(): int
    {
        if (! Schema::hasTable('attendance_logs')) {
            return 0;
        }

        $closed = 0;

        $today = Carbon::now(self::TZ)->toDateString();

        $staleStudentIds = DB::table('attendance_logs as al')
            ->join(DB::raw('(
                SELECT student_id, MAX(id) AS max_id
                FROM attendance_logs
                GROUP BY student_id
            ) AS last'), 'last.max_id', '=', 'al.id')
            ->whereRaw("LOWER(TRIM(al.status)) = 'in'")
            ->whereRaw('DATE(al.scanned_at) < ?', [$today])
            ->pluck('al.student_id');

        foreach ($staleStudentIds as $sid) {
            $student = Student::query()->find($sid);
            if (! $student) {
                continue;
            }
            if ($this->closeStaleOpenInForStudent($student)) {
                $closed++;
            }
        }

        if (Schema::hasTable('visitor_logs')) {
            $staleVisitorIds = DB::table('visitor_logs as vl')
                ->join(DB::raw('(
                    SELECT visitor_id, MAX(id) AS max_id
                    FROM visitor_logs
                    GROUP BY visitor_id
                ) AS last'), 'last.max_id', '=', 'vl.id')
                ->whereRaw("LOWER(TRIM(vl.status)) = 'in'")
                ->whereRaw('DATE(vl.scanned_at) < ?', [$today])
                ->pluck('vl.visitor_id');

            foreach ($staleVisitorIds as $vid) {
                $visitor = Visitor::query()->find($vid);
                if (! $visitor) {
                    continue;
                }
                if ($this->closeStaleOpenInForVisitor($visitor)) {
                    $closed++;
                }
            }
        }

        return $closed;
    }
}
