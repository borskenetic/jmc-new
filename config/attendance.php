<?php

return [

    /*
    | Gate picker on the attendance scanner + admin settings UI.
    */
    'section_picker_enabled' => env('ATTENDANCE_SECTION_PICKER_ENABLED', false),

    /*
    | Minimum minutes between scans for the same patron (IN or OUT).
    | Set to 0 to disable.
    */
    'scan_cooldown_minutes' => (int) env('ATTENDANCE_SCAN_COOLDOWN_MINUTES', 10),

    /*
    | Student day schedule — IN after (in_time + grace) is marked late.
    | Editable in admin UI; values below are defaults when unset in DB.
    */
    'schedule' => [
        'in_time' => env('ATTENDANCE_IN_TIME', env('SF2_CLASS_START_TIME', '07:30')),
        'out_time' => env('ATTENDANCE_OUT_TIME', '14:00'),
        'grace_minutes' => (int) env('ATTENDANCE_GRACE_MINUTES', env('SF2_TARDY_GRACE_MINUTES', 10)),
        'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),
    ],

];
