<?php

namespace App\Models;

use App\Services\StudentAttendanceSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AttendanceLog extends Model
{
    protected $fillable = [
        'student_id',
        'status',
        'is_late',
        'section',
        'gate',
        'scanned_at',
        'client_uuid',
        'gate_device_id',
        'source',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'is_late' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Whether this IN scan is late per the attendance schedule.
     * Also corrects a stale is_late column when possible.
     */
    public function isLateArrival(): bool
    {
        if (strtoupper((string) $this->status) !== 'IN' || ! $this->scanned_at) {
            return false;
        }

        $computed = app(StudentAttendanceSchedule::class)->isLate(
            $this->scanned_at,
            $this->relationLoaded('student') ? $this->student : $this->student()->first()
        );

        if (
            Schema::hasColumn('attendance_logs', 'is_late')
            && (bool) $this->is_late !== $computed
        ) {
            $this->forceFill(['is_late' => $computed])->saveQuietly();
        }

        return $computed;
    }
}
