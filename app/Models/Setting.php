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

    public const KEY_SCAN_SMS_ARRIVAL = 'scan_sms_arrival';

    public const KEY_SCAN_SMS_DEPARTURE = 'scan_sms_departure';

    public const KEY_SCAN_SMS_ARRIVAL_ENABLED = 'scan_sms_arrival_enabled';

    public const KEY_SCAN_SMS_DEPARTURE_ENABLED = 'scan_sms_departure_enabled';

    /** @var array<string, string> */
    public const SCAN_SMS_EVENT_ENABLED_KEYS = [
        'arrival' => self::KEY_SCAN_SMS_ARRIVAL_ENABLED,
        'departure' => self::KEY_SCAN_SMS_DEPARTURE_ENABLED,
    ];

    public const KEY_SMS_SIM_LOAD = 'sms_sim_load';

    public const DEFAULT_SCAN_SMS_ARRIVAL = 'Hello {contact}, your child {name} checked in at school at {time} ({status}).';

    public const DEFAULT_SCAN_SMS_DEPARTURE = 'Hello {contact}, your child {name} scanned out at school at {time} ({status}). Have a safe trip home.';

    public const KEY_STUDENT_ATTENDANCE_SCHEDULE = 'student_attendance_schedule';

    public const DEFAULT_STUDENT_ATTENDANCE_SCHEDULE = [
        'in_time' => '07:30',
        'out_time' => '14:00',
        'grace_minutes' => 10,
        'groups' => [
            'k10' => [
                'in_time' => '07:30',
                'out_time' => '14:00',
                'grace_minutes' => 10,
            ],
            'shs' => [
                'in_time' => '14:30',
                'out_time' => '14:00',
                'grace_minutes' => 10,
            ],
        ],
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
     * @return array<string, mixed>
     */
    public static function studentAttendanceSchedule(): array
    {
        $k10Defaults = self::DEFAULT_STUDENT_ATTENDANCE_SCHEDULE['groups']['k10'];
        $shsDefaults = self::DEFAULT_STUDENT_ATTENDANCE_SCHEDULE['groups']['shs'];

        $defaults = [
            'in_time' => (string) config(
                'attendance.schedule.groups.k10.in_time',
                config('attendance.schedule.in_time', $k10Defaults['in_time'])
            ),
            'out_time' => (string) config(
                'attendance.schedule.groups.k10.out_time',
                config('attendance.schedule.out_time', $k10Defaults['out_time'])
            ),
            'grace_minutes' => (int) config(
                'attendance.schedule.groups.k10.grace_minutes',
                config('attendance.schedule.grace_minutes', $k10Defaults['grace_minutes'])
            ),
            'groups' => [
                'k10' => [
                    'in_time' => (string) config(
                        'attendance.schedule.groups.k10.in_time',
                        config('attendance.schedule.in_time', $k10Defaults['in_time'])
                    ),
                    'out_time' => (string) config(
                        'attendance.schedule.groups.k10.out_time',
                        config('attendance.schedule.out_time', $k10Defaults['out_time'])
                    ),
                    'grace_minutes' => (int) config(
                        'attendance.schedule.groups.k10.grace_minutes',
                        config('attendance.schedule.grace_minutes', $k10Defaults['grace_minutes'])
                    ),
                ],
                'shs' => [
                    'in_time' => (string) config(
                        'attendance.schedule.groups.shs.in_time',
                        $shsDefaults['in_time']
                    ),
                    'out_time' => (string) config(
                        'attendance.schedule.groups.shs.out_time',
                        config('attendance.schedule.out_time', $shsDefaults['out_time'])
                    ),
                    'grace_minutes' => (int) config(
                        'attendance.schedule.groups.shs.grace_minutes',
                        config('attendance.schedule.grace_minutes', $shsDefaults['grace_minutes'])
                    ),
                ],
            ],
        ];

        $raw = static::where('key', self::KEY_STUDENT_ATTENDANCE_SCHEDULE)->value('value');
        if ($raw === null) {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $defaults;
        }

        $merged = array_merge($defaults, $decoded);
        $mergedGroups = is_array($decoded['groups'] ?? null) ? $decoded['groups'] : [];

        // Migrate legacy flat schedule into k10 when groups.k10 was never saved.
        $k10FromFlat = [];
        if (! isset($mergedGroups['k10']) || ! is_array($mergedGroups['k10'])) {
            foreach (['in_time', 'out_time', 'grace_minutes'] as $key) {
                if (array_key_exists($key, $decoded)) {
                    $k10FromFlat[$key] = $decoded[$key];
                }
            }
        }

        $merged['groups'] = [
            'k10' => array_merge(
                $defaults['groups']['k10'],
                $k10FromFlat,
                is_array($mergedGroups['k10'] ?? null) ? $mergedGroups['k10'] : []
            ),
            'shs' => array_merge(
                $defaults['groups']['shs'],
                is_array($mergedGroups['shs'] ?? null) ? $mergedGroups['shs'] : []
            ),
        ];

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    public static function setStudentAttendanceSchedule(array $schedule): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_STUDENT_ATTENDANCE_SCHEDULE],
            ['value' => json_encode($schedule, JSON_UNESCAPED_UNICODE)]
        );
    }

    public static function scanSmsArrivalTemplate(): string
    {
        $value = static::where('key', self::KEY_SCAN_SMS_ARRIVAL)->value('value');
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        $legacy = static::where('key', self::KEY_SCAN_SMS)->value('value');
        if (is_string($legacy) && trim($legacy) !== '') {
            return $legacy;
        }

        return self::DEFAULT_SCAN_SMS_ARRIVAL;
    }

    public static function scanSmsDepartureTemplate(): string
    {
        $value = static::where('key', self::KEY_SCAN_SMS_DEPARTURE)->value('value');

        return (is_string($value) && trim($value) !== '')
            ? $value
            : self::DEFAULT_SCAN_SMS_DEPARTURE;
    }

    public static function scanSmsTemplateForStatus(string $status): string
    {
        return strtoupper($status) === 'OUT'
            ? self::scanSmsDepartureTemplate()
            : self::scanSmsArrivalTemplate();
    }

    public static function isScanSmsEvent(string $event): bool
    {
        return array_key_exists($event, self::SCAN_SMS_EVENT_ENABLED_KEYS);
    }

    public static function scanSmsEventEnabled(string $event): bool
    {
        $key = self::SCAN_SMS_EVENT_ENABLED_KEYS[$event] ?? null;
        if ($key === null) {
            return true;
        }

        return static::booleanSetting($key, true);
    }

    public static function setScanSmsEventEnabled(string $event, bool $enabled): void
    {
        $key = self::SCAN_SMS_EVENT_ENABLED_KEYS[$event] ?? null;
        if ($key === null) {
            return;
        }

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $enabled ? '1' : '0']
        );
    }

    /** @return array<string, bool> */
    public static function scanSmsEventsEnabled(): array
    {
        $enabled = [];
        foreach (array_keys(self::SCAN_SMS_EVENT_ENABLED_KEYS) as $event) {
            $enabled[$event] = static::scanSmsEventEnabled($event);
        }

        return $enabled;
    }

    protected static function booleanSetting(string $key, bool $default): bool
    {
        $value = static::where('key', $key)->value('value');

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array{loaded_at: string, validity_days: int, expires_at: string, days_left: int, ok: bool}|null
     */
    public static function smsSimLoad(): ?array
    {
        $raw = static::where('key', self::KEY_SMS_SIM_LOAD)->value('value');
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || empty($decoded['loaded_at'])) {
            return null;
        }

        $loadedAt = (string) $decoded['loaded_at'];
        $validityDays = max(1, (int) ($decoded['validity_days'] ?? 15));
        $expires = \Carbon\Carbon::parse($loadedAt, config('app.timezone'))->startOfDay()->addDays($validityDays);
        $today = now(config('app.timezone'))->startOfDay();
        $daysLeft = (int) round(($expires->getTimestamp() - $today->getTimestamp()) / 86400);

        return [
            'loaded_at' => $loadedAt,
            'validity_days' => $validityDays,
            'expires_at' => $expires->toDateString(),
            'days_left' => $daysLeft,
            'ok' => $daysLeft >= 0,
        ];
    }

    public static function setSmsSimLoad(string $loadedAt, int $validityDays): void
    {
        static::updateOrCreate(
            ['key' => self::KEY_SMS_SIM_LOAD],
            ['value' => json_encode([
                'loaded_at' => $loadedAt,
                'validity_days' => max(1, $validityDays),
            ], JSON_UNESCAPED_UNICODE)]
        );
    }
}
