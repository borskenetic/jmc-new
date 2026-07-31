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

];
