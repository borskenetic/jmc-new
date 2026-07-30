<?php

namespace App\Services;

use App\Console\Commands\NormalizeStudentNames;
use App\Http\Controllers\SmsController;
use App\Models\AttendanceLog;
use App\Models\GateDevice;
use App\Models\Setting;
use App\Models\Student;
use Carbon\Carbon;

class StudentScanService
{
    public function __construct(
        protected AttendanceSessionService $sessions,
        protected StudentDeparturePolicy $departure,
        protected GateTerminalService $gates,
    ) {}

    public function resolveStudent(string $raw): ?Student
    {
        $token = trim(str_replace("\r", '', $raw));

        $student = Student::where('rfid', $token)->first();

        if (! $student) {
            $student = Student::where('qrcode', $token)->first();
        }

        $parsed = $this->parseQr($raw);

        if (! $student && $parsed['student_no']) {
            $student = Student::where('student_id', $parsed['student_no'])->first();
        }

        if (! $student && $parsed['full_name']) {
            $qrName = NormalizeStudentNames::normalizeFullName($parsed['full_name']);
            $student = Student::where('normalized_name', $qrName)->first();
        }

        return $student;
    }

    /** @return array{student_no: ?string, full_name: ?string, course: ?string} */
    public function parseQr(string $raw): array
    {
        $raw = trim(str_replace("\r", '', $raw));

        if (str_contains($raw, "\n")) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));

            return [
                'student_no' => $lines[0] ?? null,
                'full_name' => $lines[1] ?? null,
                'course' => $lines[2] ?? null,
            ];
        }

        $parts = array_map('trim', explode(',', $raw));

        if (preg_match('/^\d{2}-\d+$/', $parts[0] ?? '')) {
            return [
                'student_no' => $parts[0] ?? null,
                'full_name' => $parts[1] ?? null,
                'course' => $parts[2] ?? null,
            ];
        }

        return [
            'student_no' => null,
            'full_name' => $parts[0] ?? null,
            'course' => $parts[1] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function studentPayload(Student $student): array
    {
        return [
            'id' => $student->id,
            'student_id' => $student->student_id,
            'qrcode' => $student->qrcode,
            'rfid' => $student->rfid,
            'firstname' => $student->firstname,
            'lastname' => $student->lastname,
            'middle_initial' => $student->midname,
            'profile_picture' => $student->profile_picture,
            'normalized_name' => $student->normalized_name,
            'educational_level' => $student->educational_level?->value ?? $student->educational_level,
            'year' => $student->year,
        ];
    }

    public function lastLogForStudent(Student $student): ?AttendanceLog
    {
        return AttendanceLog::where('student_id', $student->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();
    }

    public function logoutFeedbackEnabled(): bool
    {
        if (! config('attendance.logout_feedback_enabled')) {
            return false;
        }

        return Setting::logoutFeedbackEnabled();
    }

    public function sectionPickerEnabled(): bool
    {
        if (! config('attendance.section_picker_enabled')) {
            return false;
        }

        return Setting::sectionPickerEnabled();
    }

    public function earlyOutMessage(): string
    {
        return str_replace(
            '{time}',
            $this->departure->earliestOutLabel(),
            $this->departure->blockMessage()
        );
    }

    public function recordSyncedScan(
        Student $student,
        string $status,
        Carbon $scannedAt,
        ?string $section,
        ?string $gate,
        string $clientUuid,
        GateDevice $gateDevice,
    ): AttendanceLog {
        $existing = AttendanceLog::where('client_uuid', $clientUuid)->first();
        if ($existing) {
            return $existing;
        }

        if ($section !== null && $section !== '') {
            $allowed = Setting::attendanceSections();
            if (! in_array($section, $allowed, true)) {
                $section = null;
            }
        } else {
            $section = null;
        }

        $gate = $this->gates->validateGateForScan($gate);
        if ($gate === null) {
            throw new \InvalidArgumentException('Select a valid gate on this terminal before scanning.');
        }

        $status = strtoupper(trim($status));
        if (! in_array($status, ['IN', 'OUT'], true)) {
            throw new \InvalidArgumentException('Invalid scan status.');
        }

        $log = AttendanceLog::create([
            'student_id' => $student->id,
            'section' => $section,
            'gate' => $gate,
            'status' => $status,
            'scanned_at' => $scannedAt,
            'client_uuid' => $clientUuid,
            'gate_device_id' => $gateDevice->id,
            'source' => 'gate_sync',
        ]);

        $this->sendScanSms($student, $status, $scannedAt);

        return $log;
    }

    protected function sendScanSms(Student $student, string $status, Carbon $scannedAt): void
    {
        if (empty($student->mobile_number)) {
            return;
        }

        $template = Setting::where('key', Setting::KEY_SCAN_SMS)->value('value')
            ?? 'Hello {name}, you scanned {status} at the library at {time}.';

        $message = str_replace(
            ['{name}', '{status}', '{time}'],
            [
                trim($student->firstname.' '.$student->lastname),
                $status,
                $scannedAt->copy()->timezone('Asia/Manila')->format('h:i A'),
            ],
            $template
        );

        app(SmsController::class)->sendDirect($student->mobile_number, $message);
    }
}
