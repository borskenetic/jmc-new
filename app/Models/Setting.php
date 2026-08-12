<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const KEY_LOGOUT_FEEDBACK = 'logout_feedback_enabled';

    public const KEY_SECTION_PICKER = 'section_picker_enabled';

    public const KEY_ATTENDANCE_SECTIONS = 'attendance_sections';

    public const KEY_GATE_TERMINALS = 'gate_terminals';

    public const KEY_SCAN_SMS = 'scan_sms';

    public const KEY_STUDENT_ATTENDANCE_SCHEDULE = 'student_attendance_schedule';

    public const DEFAULT_STUDENT_ATTENDANCE_SCHEDULE = [
        'in_time' => '07:30',
        'out_time' => '14:00',
        'grace_minutes' => 10,
    ];

    public const DEFAULT_GATE_TERMINALS = [
        'Main Gate',
        'North Gate',
        'South Gate',
        'East Gate',
        'West Gate',
        'Back Gate',
    ];

    public const DEFAULT_ATTENDANCE_SECTIONS = [
        'Main Building',
        'High School Building',
        'Grade School Building',
        'Gymnasium',
        'Canteen',
    ];

    protected $fillable = ['key', 'value'];

    public static function logoutFeedbackEnabled(): bool
    {
        $value = static::where('key', self::KEY_LOGOUT_FEEDBACK)->value('value');

        if ($value === null) {
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function setLogoutFeedbackEnabled(bool $enabled): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_LOGOUT_FEEDBACK],
            ['value' => $enabled ? '1' : '0']
        );
    }

    public static function sectionPickerEnabled(): bool
    {
        $value = static::where('key', self::KEY_SECTION_PICKER)->value('value');

        if ($value === null) {
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function setSectionPickerEnabled(bool $enabled): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_SECTION_PICKER],
            ['value' => $enabled ? '1' : '0']
        );
    }

    /** @return list<string> */
    public static function attendanceSections(): array
    {
        $raw = static::where('key', self::KEY_ATTENDANCE_SECTIONS)->value('value');

        if ($raw === null) {
            return self::DEFAULT_ATTENDANCE_SECTIONS;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return self::DEFAULT_ATTENDANCE_SECTIONS;
        }

        $sections = array_values(array_unique(array_filter(array_map(
            fn ($name) => trim((string) $name),
            $decoded
        ))));

        return $sections !== [] ? $sections : self::DEFAULT_ATTENDANCE_SECTIONS;
    }

    /** @param  list<string>  $sections */
    public static function setAttendanceSections(array $sections): void
    {
        $sections = array_values(array_unique(array_filter(array_map(
            fn ($name) => trim((string) $name),
            $sections
        ))));

        static::updateOrCreate(
            ['key' => self::KEY_ATTENDANCE_SECTIONS],
            ['value' => json_encode($sections, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** @return list<string> */
    public static function gateTerminals(): array
    {
        $raw = static::where('key', self::KEY_GATE_TERMINALS)->value('value');

        if ($raw === null) {
            $legacy = static::where('key', self::KEY_ATTENDANCE_SECTIONS)->value('value');
            if ($legacy !== null) {
                $decoded = json_decode($legacy, true);
                if (is_array($decoded)) {
                    $gates = array_values(array_unique(array_filter(array_map(
                        fn ($name) => trim((string) $name),
                        $decoded
                    ))));
                    if ($gates !== []) {
                        return $gates;
                    }
                }
            }

            return self::DEFAULT_GATE_TERMINALS;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return self::DEFAULT_GATE_TERMINALS;
        }

        $gates = array_values(array_unique(array_filter(array_map(
            fn ($name) => trim((string) $name),
            $decoded
        ))));

        return $gates !== [] ? $gates : self::DEFAULT_GATE_TERMINALS;
    }

    /** @param  list<string>  $gates */
    public static function setGateTerminals(array $gates): void
    {
        $gates = array_values(array_unique(array_filter(array_map(
            fn ($name) => trim((string) $name),
            $gates
        ))));

        static::updateOrCreate(
            ['key' => self::KEY_GATE_TERMINALS],
            ['value' => json_encode($gates, JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * @return array{in_time: string, out_time: string, grace_minutes: int}
     */
    public static function studentAttendanceSchedule(): array
    {
        $defaults = [
            'in_time' => (string) config(
                'attendance.schedule.in_time',
                self::DEFAULT_STUDENT_ATTENDANCE_SCHEDULE['in_time']
            ),
            'out_time' => (string) config(
                'attendance.schedule.out_time',
                self::DEFAULT_STUDENT_ATTENDANCE_SCHEDULE['out_time']
            ),
            'grace_minutes' => (int) config(
                'attendance.schedule.grace_minutes',
                self::DEFAULT_STUDENT_ATTENDANCE_SCHEDULE['grace_minutes']
            ),
        ];

        $raw = static::where('key', self::KEY_STUDENT_ATTENDANCE_SCHEDULE)->value('value');
        if ($raw === null) {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $defaults;
        }

        return [
            'in_time' => isset($decoded['in_time']) && is_string($decoded['in_time']) && $decoded['in_time'] !== ''
                ? $decoded['in_time']
                : $defaults['in_time'],
            'out_time' => isset($decoded['out_time']) && is_string($decoded['out_time']) && $decoded['out_time'] !== ''
                ? $decoded['out_time']
                : $defaults['out_time'],
            'grace_minutes' => array_key_exists('grace_minutes', $decoded)
                ? (int) $decoded['grace_minutes']
                : $defaults['grace_minutes'],
        ];
    }

    /**
     * @param  array{in_time: string, out_time: string, grace_minutes: int}  $schedule
     */
    public static function setStudentAttendanceSchedule(array $schedule): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_STUDENT_ATTENDANCE_SCHEDULE],
            ['value' => json_encode([
                'in_time' => $schedule['in_time'],
                'out_time' => $schedule['out_time'],
                'grace_minutes' => (int) $schedule['grace_minutes'],
            ], JSON_UNESCAPED_UNICODE)]
        );
    }
}
