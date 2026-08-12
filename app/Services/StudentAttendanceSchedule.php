<?php

namespace App\Services;

use App\Enums\EducationalLevel;
use App\Models\AttendanceLog;
use App\Models\Setting;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class StudentAttendanceSchedule
{
    public const GROUP_GENERAL = 'general';

    public const GROUP_SHS_DAY = 'shs_day';

    public const GROUP_SHS_EVENING = 'shs_evening';

    /** @return list<string> */
    public static function groupKeys(): array
    {
        return [self::GROUP_GENERAL, self::GROUP_SHS_DAY, self::GROUP_SHS_EVENING];
    }

    /** @return array<string, string> */
    public static function groupLabels(): array
    {
        return [
            self::GROUP_GENERAL => 'General (K–10)',
            self::GROUP_SHS_DAY => 'SHS day',
            self::GROUP_SHS_EVENING => 'SHS evening',
        ];
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

    public function resolveGroup(?Student $student = null): string
    {
        if ($student === null) {
            return self::GROUP_GENERAL;
        }

        $level = $student->educational_level;
        $levelValue = $level instanceof EducationalLevel
            ? $level->value
            : (is_string($level) ? $level : null);

        if ($levelValue === EducationalLevel::HighSchoolSenior->value) {
            $session = strtolower(trim((string) ($student->class_session ?? 'day')));

            return $session === 'evening' ? self::GROUP_SHS_EVENING : self::GROUP_SHS_DAY;
        }

        return self::GROUP_GENERAL;
    }

    /**
     * Effective times for a group on a given date (temporary override if active).
     *
     * @return array{in_time: string, out_time: string, grace_minutes: int, source: string}
     */
    public function effectiveForGroup(string $group, ?string $date = null): array
    {
        $group = $this->normalizeGroupKey($group);
        $date ??= Carbon::now($this->timezone())->toDateString();
        $permanent = $this->permanentForGroup($group);
        $temp = $this->temporary();

        if ($this->temporaryApplies($temp, $group, $date)) {
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

        return $this->effectiveForGroup($this->resolveGroup($student), $at->toDateString());
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
        $effective = $this->effectiveForGroup($this->resolveGroup($student), $date);

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
    public function permanentForGroup(string $group): array
    {
        $group = $this->normalizeGroupKey($group);
        $defaults = $this->defaultGroupTimes();
        $groups = $this->rawGroups();
        $row = $groups[$group] ?? [];

        return [
            'in_time' => $this->normalizedTime($row['in_time'] ?? null, $defaults[$group]['in_time']),
            'out_time' => $this->normalizedTime($row['out_time'] ?? null, $defaults[$group]['out_time']),
            'grace_minutes' => max(0, min(180, (int) ($row['grace_minutes'] ?? $defaults[$group]['grace_minutes']))),
        ];
    }

    /**
     * @return array{
     *   enabled: bool,
     *   in_time: ?string,
     *   out_time: ?string,
     *   starts_on: ?string,
     *   ends_on: ?string,
     *   apply_to: list<string>
     * }
     */
    public function temporary(): array
    {
        $raw = Setting::studentAttendanceSchedule();
        $temp = is_array($raw['temporary'] ?? null) ? $raw['temporary'] : [];

        $applyTo = $temp['apply_to'] ?? [self::GROUP_GENERAL];
        if (! is_array($applyTo)) {
            $applyTo = [self::GROUP_GENERAL];
        }
        $applyTo = array_values(array_intersect(self::groupKeys(), array_map('strval', $applyTo)));
        if ($applyTo === []) {
            $applyTo = [self::GROUP_GENERAL];
        }

        return [
            'enabled' => (bool) ($temp['enabled'] ?? false),
            'in_time' => isset($temp['in_time']) && $temp['in_time'] !== ''
                ? $this->normalizedTime((string) $temp['in_time'], $this->defaultInTime())
                : null,
            'out_time' => isset($temp['out_time']) && $temp['out_time'] !== ''
                ? $this->normalizedTime((string) $temp['out_time'], $this->defaultOutTime())
                : null,
            'starts_on' => $this->normalizeDate($temp['starts_on'] ?? null),
            'ends_on' => $this->normalizeDate($temp['ends_on'] ?? null),
            'apply_to' => $applyTo,
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
        foreach (self::groupKeys() as $key) {
            $row = $data['groups'][$key] ?? [];
            $current = $this->permanentForGroup($key);
            $groups[$key] = [
                'in_time' => $this->normalizedTime($row['in_time'] ?? null, $current['in_time']),
                'out_time' => $this->normalizedTime($row['out_time'] ?? null, $current['out_time']),
                'grace_minutes' => max(0, min(180, (int) ($row['grace_minutes'] ?? $current['grace_minutes']))),
            ];
        }

        $tempInput = $data['temporary'] ?? [];
        $enabled = (bool) ($tempInput['enabled'] ?? false);
        $applyTo = $tempInput['apply_to'] ?? [];
        if (! is_array($applyTo)) {
            $applyTo = [];
        }
        $applyTo = array_values(array_intersect(self::groupKeys(), array_map('strval', $applyTo)));
        if ($applyTo === []) {
            $applyTo = [self::GROUP_GENERAL];
        }

        $temporary = [
            'enabled' => $enabled,
            'in_time' => $enabled
                ? $this->normalizedTime($tempInput['in_time'] ?? null, $groups[self::GROUP_GENERAL]['in_time'])
                : ($tempInput['in_time'] ?? null ? $this->normalizedTime((string) $tempInput['in_time'], $groups[self::GROUP_GENERAL]['in_time']) : null),
            'out_time' => $enabled
                ? $this->normalizedTime($tempInput['out_time'] ?? null, $groups[self::GROUP_GENERAL]['out_time'])
                : ($tempInput['out_time'] ?? null ? $this->normalizedTime((string) $tempInput['out_time'], $groups[self::GROUP_GENERAL]['out_time']) : null),
            'starts_on' => $this->normalizeDate($tempInput['starts_on'] ?? null),
            'ends_on' => $this->normalizeDate($tempInput['ends_on'] ?? null),
            'apply_to' => $applyTo,
        ];

        Setting::setStudentAttendanceSchedule([
            'groups' => $groups,
            'temporary' => $temporary,
            // Keep legacy flat keys synced to general for older readers.
            'in_time' => $groups[self::GROUP_GENERAL]['in_time'],
            'out_time' => $groups[self::GROUP_GENERAL]['out_time'],
            'grace_minutes' => $groups[self::GROUP_GENERAL]['grace_minutes'],
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
            'groups' => collect(self::groupKeys())
                ->mapWithKeys(fn ($key) => [$key => $this->permanentForGroup($key)])
                ->all(),
            'temporary' => $this->temporary(),
        ];
    }

    /** @param  array<string, mixed>  $temp */
    protected function temporaryApplies(array $temp, string $group, string $date): bool
    {
        if (! ($temp['enabled'] ?? false)) {
            return false;
        }
        if (! in_array($group, $temp['apply_to'] ?? [], true)) {
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

    /** @return array<string, array<string, mixed>> */
    protected function rawGroups(): array
    {
        $raw = Setting::studentAttendanceSchedule();
        if (isset($raw['groups']) && is_array($raw['groups'])) {
            return $raw['groups'];
        }

        // Legacy single-schedule → seed all groups from flat keys.
        $legacy = [
            'in_time' => $raw['in_time'] ?? $this->defaultInTime(),
            'out_time' => $raw['out_time'] ?? $this->defaultOutTime(),
            'grace_minutes' => (int) ($raw['grace_minutes'] ?? $this->defaultGraceMinutes()),
        ];

        return [
            self::GROUP_GENERAL => $legacy,
            self::GROUP_SHS_DAY => $legacy,
            self::GROUP_SHS_EVENING => [
                'in_time' => '16:00',
                'out_time' => '21:00',
                'grace_minutes' => $legacy['grace_minutes'],
            ],
        ];
    }

    /** @return array<string, array{in_time: string, out_time: string, grace_minutes: int}> */
    protected function defaultGroupTimes(): array
    {
        $in = $this->defaultInTime();
        $out = $this->defaultOutTime();
        $grace = $this->defaultGraceMinutes();

        return [
            self::GROUP_GENERAL => ['in_time' => $in, 'out_time' => $out, 'grace_minutes' => $grace],
            self::GROUP_SHS_DAY => ['in_time' => $in, 'out_time' => $out, 'grace_minutes' => $grace],
            self::GROUP_SHS_EVENING => ['in_time' => '16:00', 'out_time' => '21:00', 'grace_minutes' => $grace],
        ];
    }

    protected function normalizeGroupKey(string $group): string
    {
        return in_array($group, self::groupKeys(), true) ? $group : self::GROUP_GENERAL;
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
