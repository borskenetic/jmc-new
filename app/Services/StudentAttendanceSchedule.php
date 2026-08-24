<?php

namespace App\Services;

use App\Enums\EducationalLevel;
use App\Models\AttendanceLog;
use App\Models\GradeSection;
use App\Models\Setting;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class StudentAttendanceSchedule
{
    public const GROUP_K10 = 'k10';

    public const GROUP_SHS = 'shs';

    /** @return list<string> */
    public static function groups(): array
    {
        return [self::GROUP_K10, self::GROUP_SHS];
    }

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
     * Resolve schedule group for a student: SHS (Grade 11–12) vs Kinder–Grade 10.
     */
    public function groupForStudent(?Student $student = null): string
    {
        if (! $student) {
            return self::GROUP_K10;
        }

        $level = $student->educational_level;
        if ($level instanceof EducationalLevel) {
            if ($level === EducationalLevel::HighSchoolSenior) {
                return self::GROUP_SHS;
            }
        } elseif (is_string($level) && $level === EducationalLevel::HighSchoolSenior->value) {
            return self::GROUP_SHS;
        } else {
            $raw = $student->getRawOriginal('educational_level');
            if (is_string($raw) && $raw === EducationalLevel::HighSchoolSenior->value) {
                return self::GROUP_SHS;
            }
        }

        $year = trim((string) ($student->year ?? ''));
        if ($year !== '' && GradeSection::isSeniorHighGrade($year)) {
            return self::GROUP_SHS;
        }

        return self::GROUP_K10;
    }

    /**
     * Effective times for a date and group (temporary override if active).
     *
     * @return array{in_time: string, out_time: string, grace_minutes: int, source: string, group: string}
     */
    public function effective(?string $date = null, string $group = self::GROUP_K10): array
    {
        $group = $this->normalizeGroup($group);
        $date ??= Carbon::now($this->timezone())->toDateString();
        $permanent = $this->permanent($group);
        $temp = $this->temporary($group);

        if ($this->temporaryApplies($temp, $date)) {
            return [
                'in_time' => $this->normalizedTime($temp['in_time'] ?? null, $permanent['in_time']),
                'out_time' => $this->normalizedTime($temp['out_time'] ?? null, $permanent['out_time']),
                'grace_minutes' => $permanent['grace_minutes'],
                'source' => 'temporary',
                'group' => $group,
            ];
        }

        return $permanent + ['source' => 'permanent', 'group' => $group];
    }

    /**
     * @return array{in_time: string, out_time: string, grace_minutes: int, source: string, group: string}
     */
    public function effectiveForStudent(?Student $student = null, ?Carbon $at = null): array
    {
        $at = ($at ?? Carbon::now($this->timezone()))->copy()->timezone($this->timezone());

        return $this->effective($at->toDateString(), $this->groupForStudent($student));
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
        $effective = $this->effective($date, $this->groupForStudent($student));

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
    public function permanent(string $group = self::GROUP_K10): array
    {
        $group = $this->normalizeGroup($group);
        $raw = Setting::studentAttendanceSchedule();
        $groupRaw = is_array($raw['groups'][$group] ?? null) ? $raw['groups'][$group] : [];

        // Legacy flat keys / groups.general map to Kinder–Grade 10.
        $legacyGroup = is_array($raw['groups']['general'] ?? null) ? $raw['groups']['general'] : [];
        $flatFallback = $group === self::GROUP_K10
            ? [
                'in_time' => $raw['in_time'] ?? ($legacyGroup['in_time'] ?? null),
                'out_time' => $raw['out_time'] ?? ($legacyGroup['out_time'] ?? null),
                'grace_minutes' => $raw['grace_minutes'] ?? ($legacyGroup['grace_minutes'] ?? null),
            ]
            : [
                'in_time' => null,
                'out_time' => null,
                'grace_minutes' => null,
            ];

        return [
            'in_time' => $this->normalizedTime(
                $groupRaw['in_time'] ?? $flatFallback['in_time'],
                $this->defaultInTime($group)
            ),
            'out_time' => $this->normalizedTime(
                $groupRaw['out_time'] ?? $flatFallback['out_time'],
                $this->defaultOutTime($group)
            ),
            'grace_minutes' => max(0, min(180, (int) (
                $groupRaw['grace_minutes'] ?? ($flatFallback['grace_minutes'] ?? $this->defaultGraceMinutes($group))
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
    public function temporary(string $group = self::GROUP_K10): array
    {
        $group = $this->normalizeGroup($group);
        $raw = Setting::studentAttendanceSchedule();
        $tempRoot = is_array($raw['temporary'] ?? null) ? $raw['temporary'] : [];
        $permanent = $this->permanent($group);

        // Prefer per-group temporary times; fall back to legacy flat temporary for k10.
        $groupTemp = is_array($tempRoot[$group] ?? null) ? $tempRoot[$group] : [];
        $legacyIn = $group === self::GROUP_K10 ? ($tempRoot['in_time'] ?? null) : null;
        $legacyOut = $group === self::GROUP_K10 ? ($tempRoot['out_time'] ?? null) : null;

        $inRaw = $groupTemp['in_time'] ?? $legacyIn;
        $outRaw = $groupTemp['out_time'] ?? $legacyOut;

        return [
            'enabled' => (bool) ($tempRoot['enabled'] ?? false),
            'in_time' => isset($inRaw) && $inRaw !== ''
                ? $this->normalizedTime((string) $inRaw, $permanent['in_time'])
                : null,
            'out_time' => isset($outRaw) && $outRaw !== ''
                ? $this->normalizedTime((string) $outRaw, $permanent['out_time'])
                : null,
            'starts_on' => $this->normalizeDate($tempRoot['starts_on'] ?? null),
            'ends_on' => $this->normalizeDate($tempRoot['ends_on'] ?? null),
        ];
    }

    /**
     * @param  array{
     *   groups?: array<string, array{in_time?: string, out_time?: string, grace_minutes?: int|string}>,
     *   temporary?: array<string, mixed>
     * }  $data
     */
    public function update(array $data): void
    {
        $groups = [];
        foreach (self::groups() as $group) {
            $input = is_array($data['groups'][$group] ?? null) ? $data['groups'][$group] : [];
            $current = $this->permanent($group);
            $groups[$group] = [
                'in_time' => $this->normalizedTime($input['in_time'] ?? null, $current['in_time']),
                'out_time' => $this->normalizedTime($input['out_time'] ?? null, $current['out_time']),
                'grace_minutes' => max(0, min(180, (int) ($input['grace_minutes'] ?? $current['grace_minutes']))),
            ];
        }

        $tempInput = is_array($data['temporary'] ?? null) ? $data['temporary'] : [];
        $enabled = (bool) ($tempInput['enabled'] ?? false);
        $startsOn = $this->normalizeDate($tempInput['starts_on'] ?? null);
        $endsOn = $this->normalizeDate($tempInput['ends_on'] ?? null);

        $temporary = [
            'enabled' => $enabled,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ];

        foreach (self::groups() as $group) {
            $groupTemp = is_array($tempInput[$group] ?? null) ? $tempInput[$group] : [];
            $permanent = $groups[$group];

            $in = ($groupTemp['in_time'] ?? null) !== null && $groupTemp['in_time'] !== ''
                ? $this->normalizedTime((string) $groupTemp['in_time'], $permanent['in_time'])
                : null;
            $out = ($groupTemp['out_time'] ?? null) !== null && $groupTemp['out_time'] !== ''
                ? $this->normalizedTime((string) $groupTemp['out_time'], $permanent['out_time'])
                : null;

            if ($enabled) {
                $in = $this->normalizedTime($groupTemp['in_time'] ?? null, $permanent['in_time']);
                $out = $this->normalizedTime($groupTemp['out_time'] ?? null, $permanent['out_time']);
            }

            $temporary[$group] = [
                'in_time' => $in,
                'out_time' => $out,
            ];
        }

        // Keep flat keys in sync with k10 for older readers.
        Setting::setStudentAttendanceSchedule([
            'in_time' => $groups[self::GROUP_K10]['in_time'],
            'out_time' => $groups[self::GROUP_K10]['out_time'],
            'grace_minutes' => $groups[self::GROUP_K10]['grace_minutes'],
            'groups' => $groups,
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
        if ($student) {
            $effective = $this->effectiveForStudent($student);

            return [
                'in_time' => $effective['in_time'],
                'out_time' => $effective['out_time'],
                'grace_minutes' => $effective['grace_minutes'],
                'group' => $effective['group'],
                'timezone' => $this->timezone(),
                'out_allowed_from' => $this->outAllowedFrom(),
                'temporary' => $this->temporary($effective['group']),
            ];
        }

        $groups = [];
        foreach (self::groups() as $group) {
            $effective = $this->effective(null, $group);
            $groups[$group] = [
                'in_time' => $effective['in_time'],
                'out_time' => $effective['out_time'],
                'grace_minutes' => $effective['grace_minutes'],
                'temporary' => $this->temporary($group),
            ];
        }

        $k10 = $groups[self::GROUP_K10];

        return [
            'in_time' => $k10['in_time'],
            'out_time' => $k10['out_time'],
            'grace_minutes' => $k10['grace_minutes'],
            'timezone' => $this->timezone(),
            'out_allowed_from' => $this->outAllowedFrom(),
            'groups' => $groups,
            'temporary' => [
                'enabled' => (bool) ($k10['temporary']['enabled'] ?? false),
                'starts_on' => $k10['temporary']['starts_on'] ?? null,
                'ends_on' => $k10['temporary']['ends_on'] ?? null,
                self::GROUP_K10 => [
                    'in_time' => $k10['temporary']['in_time'] ?? null,
                    'out_time' => $k10['temporary']['out_time'] ?? null,
                ],
                self::GROUP_SHS => [
                    'in_time' => $groups[self::GROUP_SHS]['temporary']['in_time'] ?? null,
                    'out_time' => $groups[self::GROUP_SHS]['temporary']['out_time'] ?? null,
                ],
            ],
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

    protected function normalizeGroup(string $group): string
    {
        return in_array($group, self::groups(), true) ? $group : self::GROUP_K10;
    }

    protected function defaultInTime(string $group = self::GROUP_K10): string
    {
        $group = $this->normalizeGroup($group);

        return (string) config(
            "attendance.schedule.groups.{$group}.in_time",
            $group === self::GROUP_SHS
                ? '14:30'
                : config('attendance.schedule.in_time', config('sf2.class_start_time', '07:30'))
        );
    }

    protected function defaultOutTime(string $group = self::GROUP_K10): string
    {
        $group = $this->normalizeGroup($group);

        return (string) config(
            "attendance.schedule.groups.{$group}.out_time",
            config('attendance.schedule.out_time', '14:00')
        );
    }

    protected function defaultGraceMinutes(string $group = self::GROUP_K10): int
    {
        $group = $this->normalizeGroup($group);

        return (int) config(
            "attendance.schedule.groups.{$group}.grace_minutes",
            config(
                'attendance.schedule.grace_minutes',
                config('sf2.tardy_grace_minutes', 10)
            )
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
