<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Setting;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class StudentAttendanceSchedule
{
    public function timezone(): string
    {
        return (string) config('attendance.schedule.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /** Earliest time an OUT scan may be recorded (default 11:00). */
    public function outAllowedFrom(): string
    {
        return $this->normalizedTime(
            (string) config('attendance.schedule.out_allowed_from', '11:00'),
            '11:00'
        );
    }

    public function outAllowedFromLabel(): string
    {
        return $this->labelForTime($this->outAllowedFrom());
    }

    /**
     * Effective times for a date (temporary override if active).
     *
     * @return array{in_time: string, out_time: string, grace_minutes: int, source: string}
     */
    public function effective(?string $date = null): array
    {
        $date ??= Carbon::now($this->timezone())->toDateString();
        $permanent = $this->permanent();
        $temp = $this->temporary();

        if ($this->temporaryApplies($temp, $date)) {
            return [
                'in_time' => $this->normalizedTime($temp['in_time'] ?? null, $permanent['in_time']),
                'out_time' => $this->normalizedTime($temp['out_time'] ?? null, $permanent['out_time']),
                'grace_minutes' => $permanent['grace_minutes'],
                'source' => 'temporary',
            ];
        }

        return $permanent + ['source' => 'permanent'];
    }

    /**
     * @return array{in_time: string, out_time: string, grace_minutes: int, source: string}
     */
    public function effectiveForStudent(?Student $student = null, ?Carbon $at = null): array
    {
        $at = ($at ?? Carbon::now($this->timezone()))->copy()->timezone($this->timezone());

        return $this->effective($at->toDateString());
    }

    public function inTime(?Student $student = null, ?Carbon $at = null): string
    {
        return $this->effectiveForStudent($student, $at)['in_time'];
    }

    public function outTime(?Student $student = null, ?Carbon $at = null): string
    {
        return $this->effectiveForStudent($student, $at)['out_time'];
    }

    public function graceMinutes(?Student $student = null, ?Carbon $at = null): int
    {
        return (int) $this->effectiveForStudent($student, $at)['grace_minutes'];
    }

    public function inTimeLabel(?Student $student = null, ?Carbon $at = null): string
    {
        return $this->labelForTime($this->inTime($student, $at));
    }

    public function outTimeLabel(?Student $student = null, ?Carbon $at = null): string
    {
        return $this->labelForTime($this->outTime($student, $at));
    }

    public function lateCutoffLabel(?Student $student = null, ?Carbon $at = null): string
    {
        $at = ($at ?? Carbon::now($this->timezone()))->copy()->timezone($this->timezone());

        return $this->lateCutoffForDate($at->toDateString(), $student)->format('g:i A');
    }

    public function lateCutoffForDate(string $date, ?Student $student = null): Carbon
    {
        $effective = $this->effective($date);

        return Carbon::parse($date.' '.$effective['in_time'], $this->timezone())
            ->addMinutes((int) $effective['grace_minutes']);
    }

    public function isLate(?Carbon $at = null, ?Student $student = null): bool
    {
        $at = ($at ?? Carbon::now($this->timezone()))->copy()->timezone($this->timezone());

        return $at->gt($this->lateCutoffForDate($at->toDateString(), $student));
    }

    public function isOutAllowed(?Carbon $at = null): bool
    {
        $at = ($at ?? Carbon::now($this->timezone()))->copy()->timezone($this->timezone());
        $allowed = Carbon::parse($at->toDateString().' '.$this->outAllowedFrom(), $this->timezone());

        return $at->gte($allowed);
    }

    /**
     * Daily scan policy: one IN and one OUT per student per calendar day.
     *
     * @return array{
     *   next_status: ?string,
     *   blocked: bool,
     *   type: ?string,
     *   message: ?string,
     *   allowed_after: ?string,
     *   has_in: bool,
     *   has_out: bool
     * }
     */
    public function dailyScanDecision(Student $student, ?Carbon $at = null): array
    {
        $at = ($at ?? Carbon::now($this->timezone()))->copy()->timezone($this->timezone());
        $date = $at->toDateString();

        $hasIn = $this->hasStatusOnDate($student->id, 'IN', $date);
        $hasOut = $this->hasStatusOnDate($student->id, 'OUT', $date);

        if (! $hasIn) {
            return [
                'next_status' => 'IN',
                'blocked' => false,
                'type' => null,
                'message' => null,
                'allowed_after' => null,
                'has_in' => false,
                'has_out' => $hasOut,
            ];
        }

        if ($hasOut) {
            return [
                'next_status' => null,
                'blocked' => true,
                'type' => 'already_complete',
                'message' => 'This student already has IN and OUT recorded for today.',
                'allowed_after' => null,
                'has_in' => true,
                'has_out' => true,
            ];
        }

        if (! $this->isOutAllowed($at)) {
            return [
                'next_status' => 'OUT',
                'blocked' => true,
                'type' => 'out_too_early',
                'message' => 'Check-out is only allowed from '.$this->outAllowedFromLabel().' onward.',
                'allowed_after' => $this->outAllowedFromLabel(),
                'has_in' => true,
                'has_out' => false,
            ];
        }

        return [
            'next_status' => 'OUT',
            'blocked' => false,
            'type' => null,
            'message' => null,
            'allowed_after' => null,
            'has_in' => true,
            'has_out' => false,
        ];
    }

    public function hasStatusOnDate(int $studentId, string $status, string $date): bool
    {
        return AttendanceLog::query()
            ->where('student_id', $studentId)
            ->where('status', strtoupper($status))
            ->whereDate('scanned_at', $date)
            ->exists();
    }

    /**
     * @return array{in_time: string, out_time: string, grace_minutes: int}
     */
    public function permanent(): array
    {
        $raw = Setting::studentAttendanceSchedule();

        // Prefer flat keys; fall back to legacy groups.general if present.
        $legacyGroup = is_array($raw['groups']['general'] ?? null) ? $raw['groups']['general'] : [];

        return [
            'in_time' => $this->normalizedTime(
                $raw['in_time'] ?? ($legacyGroup['in_time'] ?? null),
                $this->defaultInTime()
            ),
            'out_time' => $this->normalizedTime(
                $raw['out_time'] ?? ($legacyGroup['out_time'] ?? null),
                $this->defaultOutTime()
            ),
            'grace_minutes' => max(0, min(180, (int) (
                $raw['grace_minutes'] ?? ($legacyGroup['grace_minutes'] ?? $this->defaultGraceMinutes())
            ))),
        ];
    }

    /**
     * @return array{
     *   enabled: bool,
     *   in_time: ?string,
     *   out_time: ?string,
     *   starts_on: ?string,
     *   ends_on: ?string
     * }
     */
    public function temporary(): array
    {
        $raw = Setting::studentAttendanceSchedule();
        $temp = is_array($raw['temporary'] ?? null) ? $raw['temporary'] : [];
        $permanent = $this->permanent();

        return [
            'enabled' => (bool) ($temp['enabled'] ?? false),
            'in_time' => isset($temp['in_time']) && $temp['in_time'] !== ''
                ? $this->normalizedTime((string) $temp['in_time'], $permanent['in_time'])
                : null,
            'out_time' => isset($temp['out_time']) && $temp['out_time'] !== ''
                ? $this->normalizedTime((string) $temp['out_time'], $permanent['out_time'])
                : null,
            'starts_on' => $this->normalizeDate($temp['starts_on'] ?? null),
            'ends_on' => $this->normalizeDate($temp['ends_on'] ?? null),
        ];
    }

    /**
     * @param  array{
     *   in_time?: string,
     *   out_time?: string,
     *   grace_minutes?: int|string,
     *   temporary?: array<string, mixed>
     * }  $data
     */
    public function update(array $data): void
    {
        $current = $this->permanent();
        $permanent = [
            'in_time' => $this->normalizedTime($data['in_time'] ?? null, $current['in_time']),
            'out_time' => $this->normalizedTime($data['out_time'] ?? null, $current['out_time']),
            'grace_minutes' => max(0, min(180, (int) ($data['grace_minutes'] ?? $current['grace_minutes']))),
        ];

        $tempInput = $data['temporary'] ?? [];
        $enabled = (bool) ($tempInput['enabled'] ?? false);

        $temporary = [
            'enabled' => $enabled,
            'in_time' => ($tempInput['in_time'] ?? null) !== null && $tempInput['in_time'] !== ''
                ? $this->normalizedTime((string) $tempInput['in_time'], $permanent['in_time'])
                : null,
            'out_time' => ($tempInput['out_time'] ?? null) !== null && $tempInput['out_time'] !== ''
                ? $this->normalizedTime((string) $tempInput['out_time'], $permanent['out_time'])
                : null,
            'starts_on' => $this->normalizeDate($tempInput['starts_on'] ?? null),
            'ends_on' => $this->normalizeDate($tempInput['ends_on'] ?? null),
        ];

        if ($enabled) {
            $temporary['in_time'] = $this->normalizedTime($tempInput['in_time'] ?? null, $permanent['in_time']);
            $temporary['out_time'] = $this->normalizedTime($tempInput['out_time'] ?? null, $permanent['out_time']);
        }

        Setting::setStudentAttendanceSchedule([
            'in_time' => $permanent['in_time'],
            'out_time' => $permanent['out_time'],
            'grace_minutes' => $permanent['grace_minutes'],
            'temporary' => $temporary,
        ]);
    }

    /**
     * Recompute is_late on existing IN rows using the schedule that applies to each scan.
     *
     * @return array{updated: int, scanned: int}
     */
    public function backfillLateFlags(?string $from = null, ?string $to = null): array
    {
        if (! Schema::hasColumn('attendance_logs', 'is_late')) {
            return ['updated' => 0, 'scanned' => 0];
        }

        $query = AttendanceLog::query()
            ->with('student')
            ->where('status', 'IN')
            ->orderBy('id');

        if ($from) {
            $query->whereDate('scanned_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('scanned_at', '<=', $to);
        }

        $updated = 0;
        $scanned = 0;

        $query->chunkById(200, function ($logs) use (&$updated, &$scanned) {
            foreach ($logs as $log) {
                $scanned++;
                if (! $log->scanned_at) {
                    continue;
                }
                $shouldBeLate = $this->isLate($log->scanned_at, $log->student);
                if ((bool) $log->is_late === $shouldBeLate) {
                    continue;
                }
                $log->is_late = $shouldBeLate;
                $log->save();
                $updated++;
            }
        });

        return ['updated' => $updated, 'scanned' => $scanned];
    }

    /** @return array<string, mixed> */
    public function toArray(?Student $student = null): array
    {
        $effective = $this->effectiveForStudent($student);

        return [
            'in_time' => $effective['in_time'],
            'out_time' => $effective['out_time'],
            'grace_minutes' => $effective['grace_minutes'],
            'timezone' => $this->timezone(),
            'out_allowed_from' => $this->outAllowedFrom(),
            'temporary' => $this->temporary(),
        ];
    }

    /** @param  array<string, mixed>  $temp */
    protected function temporaryApplies(array $temp, string $date): bool
    {
        if (! ($temp['enabled'] ?? false)) {
            return false;
        }
        $start = $temp['starts_on'] ?? null;
        $end = $temp['ends_on'] ?? null;
        if (! $start || ! $end) {
            return false;
        }
        if ($date < $start || $date > $end) {
            return false;
        }
        if (empty($temp['in_time']) || empty($temp['out_time'])) {
            return false;
        }

        return true;
    }

    protected function defaultInTime(): string
    {
        return (string) config('attendance.schedule.in_time', config('sf2.class_start_time', '07:30'));
    }

    protected function defaultOutTime(): string
    {
        return (string) config('attendance.schedule.out_time', '14:00');
    }

    protected function defaultGraceMinutes(): int
    {
        return (int) config(
            'attendance.schedule.grace_minutes',
            config('sf2.tardy_grace_minutes', 10)
        );
    }

    protected function normalizedTime(?string $value, string $fallback): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        try {
            return Carbon::createFromFormat('H:i', $value, $this->timezone())->format('H:i');
        } catch (\Throwable) {
            try {
                return Carbon::parse($value, $this->timezone())->format('H:i');
            } catch (\Throwable) {
                return $fallback;
            }
        }
    }

    protected function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $this->timezone())->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function labelForTime(string $time): string
    {
        return Carbon::today($this->timezone())
            ->setTimeFromTimeString($time)
            ->format('g:i A');
    }
}
