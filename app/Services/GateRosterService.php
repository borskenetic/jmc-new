<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Setting;
use App\Models\Student;
use Carbon\Carbon;

class GateRosterService
{
    public function __construct(
        protected StudentScanService $scanService,
        protected StudentDeparturePolicy $departure,
    ) {}

    /** @return array<string, mixed> */
    public function build(?Carbon $since = null): array
    {
        $serverTime = now()->toIso8601String();

        $studentsQuery = Student::query()->orderBy('id');
        if ($since) {
            $studentsQuery->where(function ($q) use ($since) {
                $q->where('updated_at', '>=', $since)
                    ->orWhere('created_at', '>=', $since);
            });
        }

        $students = $studentsQuery->get();

        $studentIds = $since
            ? $students->pluck('id')
            : Student::query()->pluck('id');

        $lastLogs = $this->lastLogsForStudents($studentIds);

        $roster = $students->map(function (Student $student) use ($lastLogs) {
            $payload = $this->scanService->studentPayload($student);
            $last = $lastLogs[$student->id] ?? null;
            $payload['last_log'] = $last ? [
                'status' => strtoupper((string) $last->status),
                'scanned_at' => $last->scanned_at?->toIso8601String(),
                'section' => $last->section,
                'gate' => $last->gate,
            ] : null;

            return $payload;
        })->values();

        $logsSince = null;
        if ($since) {
            $logsSince = AttendanceLog::query()
                ->where('scanned_at', '>=', $since)
                ->orderBy('scanned_at')
                ->get()
                ->map(fn (AttendanceLog $log) => [
                    'student_id' => $log->student_id,
                    'status' => strtoupper((string) $log->status),
                    'scanned_at' => $log->scanned_at?->toIso8601String(),
                    'section' => $log->section,
                    'gate' => $log->gate,
                    'client_uuid' => $log->client_uuid,
                ])
                ->values();
        }

        return [
            'server_time' => $serverTime,
            'since' => $since?->toIso8601String(),
            'full_snapshot' => $since === null,
            'settings' => $this->settingsPayload(),
            'students' => $roster,
            'logs_since' => $logsSince,
        ];
    }

    /** @return array<string, mixed> */
    public function settingsPayload(): array
    {
        return [
            'logout_feedback_enabled' => $this->scanService->logoutFeedbackEnabled(),
            'section_picker_enabled' => $this->scanService->sectionPickerEnabled(),
            'attendance_sections' => Setting::attendanceSections(),
            'gate_terminals' => Setting::gateTerminals(),
            'early_departure' => [
                // Offline gate terminals allow IN/OUT at any time (no 4pm block).
                'enabled' => false,
                'educational_levels' => config('patron.early_departure.educational_levels', ['grade_school']),
                'message' => config('patron.early_departure.message'),
                'timezone' => $this->departure->timezone(),
                'earliest_out' => config('patron.early_departure.earliest_out', '16:00'),
                'earliest_out_label' => $this->departure->earliestOutLabel(),
            ],
            'scan_cooldown_minutes' => (int) config('attendance.scan_cooldown_minutes', 10),
            'timezone' => $this->departure->timezone(),
        ];
    }

    /** @param  \Illuminate\Support\Collection<int, int>|iterable<int, int>  $studentIds */
    protected function lastLogsForStudents(iterable $studentIds): array
    {
        $ids = collect($studentIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $logs = AttendanceLog::query()
            ->whereIn('student_id', $ids)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->get()
            ->unique('student_id')
            ->keyBy('student_id');

        return $logs->all();
    }
}
