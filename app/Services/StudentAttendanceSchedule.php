<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

class StudentAttendanceSchedule
{
    public function timezone(): string
    {
        return (string) config('attendance.schedule.timezone', config('app.timezone', 'Asia/Manila'));
    }

    public function inTime(): string
    {
        return $this->normalizedTime($this->raw()['in_time'] ?? null, $this->defaultInTime());
    }

    public function outTime(): string
    {
        return $this->normalizedTime($this->raw()['out_time'] ?? null, $this->defaultOutTime());
    }

    public function graceMinutes(): int
    {
        $grace = $this->raw()['grace_minutes'] ?? null;

        if ($grace === null || $grace === '') {
            return $this->defaultGraceMinutes();
        }

        return max(0, min(180, (int) $grace));
    }

    public function inTimeLabel(): string
    {
        return $this->labelForTime($this->inTime());
    }

    public function outTimeLabel(): string
    {
        return $this->labelForTime($this->outTime());
    }

    public function lateCutoffLabel(): string
    {
        return Carbon::today($this->timezone())
            ->setTimeFromTimeString($this->inTime())
            ->addMinutes($this->graceMinutes())
            ->format('g:i A');
    }

    /**
     * First IN after this instant counts as late/tardy.
     */
    public function lateCutoffForDate(string $date): Carbon
    {
        return Carbon::parse($date.' '.$this->inTime(), $this->timezone())
            ->addMinutes($this->graceMinutes());
    }

    public function isLate(?Carbon $at = null): bool
    {
        $at = ($at ?? Carbon::now($this->timezone()))->copy()->timezone($this->timezone());

        return $at->gt($this->lateCutoffForDate($at->toDateString()));
    }

    public function isEarlyOut(?Carbon $at = null): bool
    {
        $at = ($at ?? Carbon::now($this->timezone()))->copy()->timezone($this->timezone());
        $out = Carbon::parse($at->toDateString().' '.$this->outTime(), $this->timezone());

        return $at->lt($out);
    }

    /** @return array{in_time: string, out_time: string, grace_minutes: int, timezone: string} */
    public function toArray(): array
    {
        return [
            'in_time' => $this->inTime(),
            'out_time' => $this->outTime(),
            'grace_minutes' => $this->graceMinutes(),
            'timezone' => $this->timezone(),
        ];
    }

    /**
     * @param  array{in_time?: string, out_time?: string, grace_minutes?: int|string}  $data
     */
    public function update(array $data): void
    {
        Setting::setStudentAttendanceSchedule([
            'in_time' => $this->normalizedTime($data['in_time'] ?? null, $this->inTime()),
            'out_time' => $this->normalizedTime($data['out_time'] ?? null, $this->outTime()),
            'grace_minutes' => max(0, min(180, (int) ($data['grace_minutes'] ?? $this->graceMinutes()))),
        ]);
    }

    /** @return array<string, mixed> */
    protected function raw(): array
    {
        return Setting::studentAttendanceSchedule();
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

    protected function labelForTime(string $time): string
    {
        return Carbon::today($this->timezone())
            ->setTimeFromTimeString($time)
            ->format('g:i A');
    }
}
