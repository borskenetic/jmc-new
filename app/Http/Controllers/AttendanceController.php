<?php

namespace App\Http\Controllers;

use App\Console\Commands\NormalizeStudentNames;
use App\Models\AttendanceLog;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\Student;
use App\Models\Visitor;
use App\Models\VisitorLog;
use App\Services\AttendanceSessionService;
use App\Services\FaceMatchService;
use App\Services\GateTerminalService;
use App\Services\StudentAttendanceSchedule;
use App\Services\StudentDeparturePolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function showScanner()
    {
        return view('attendance.scan', $this->scannerViewData());
    }

    public function showFaceScanner(FaceMatchService $faces)
    {
        if (! config('face.enabled')) {
            abort(404);
        }

        return view('attendance.face_scan', array_merge($this->scannerViewData(), [
            'faceEnrolledCount' => $faces->enrolledCount(),
            'faceModelCdn' => config('face.model_cdn'),
        ]));
    }

    protected function effectiveLogoutFeedbackEnabled(): bool
    {
        if (! config('attendance.logout_feedback_enabled')) {
            return false;
        }

        return Setting::logoutFeedbackEnabled();
    }

    protected function effectiveSectionPickerEnabled(): bool
    {
        if (! config('attendance.section_picker_enabled')) {
            return false;
        }

        return Setting::sectionPickerEnabled();
    }

    /** @return array<string, mixed> */
    protected function scannerViewData(): array
    {
        $departure = app(StudentDeparturePolicy::class);

        return [
            'logoutFeedbackEnabled' => $this->effectiveLogoutFeedbackEnabled(),
            'sectionPickerEnabled' => $this->effectiveSectionPickerEnabled(),
            'attendanceSections' => Setting::attendanceSections(),
            'gateTerminals' => Setting::gateTerminals(),
            'earlyDepartureEnabled' => $departure->isEnabled(),
            'earlyDepartureCutoffLabel' => $departure->earliestOutLabel(),
        ];
    }

    public function feedbackSettings()
    {
        if (! config('attendance.logout_feedback_enabled')) {
            abort(404);
        }

        return view('attendance.feedback_settings', [
            'enabled' => Setting::logoutFeedbackEnabled(),
        ]);
    }

    public function updateFeedbackSettings(Request $request)
    {
        if (! config('attendance.logout_feedback_enabled')) {
            abort(404);
        }

        $request->validate([
            'enabled' => 'required|in:0,1',
        ]);

        Setting::setLogoutFeedbackEnabled($request->input('enabled') === '1');

        return back()->with(
            'success',
            $request->input('enabled') === '1'
                ? 'Logout feedback is now enabled on the gate terminal.'
                : 'Logout feedback is now disabled on the gate terminal.'
        );
    }

    public function gateSettings()
    {
        return view('attendance.gate_settings', [
            'gates' => Setting::gateTerminals(),
        ]);
    }

    public function updateGateSettings(Request $request, GateTerminalService $gates)
    {
        $request->validate([
            'gates' => 'required|array|min:1',
            'gates.*' => 'required|string|max:120|distinct',
        ]);

        $gateList = array_values(array_unique(array_filter(array_map(
            fn ($name) => trim((string) $name),
            $request->input('gates', [])
        ))));

        Setting::setGateTerminals($gateList);
        $gates->releaseGatesNotInList($gateList);

        return back()->with(
            'success',
            'Saved '.count($gateList).' gate(s). Kiosks will only show gates not already in use.'
        );
    }

    public function scheduleSettings(StudentAttendanceSchedule $schedule)
    {
        return view('attendance.schedule_settings', [
            'permanentK10' => $schedule->permanent(StudentAttendanceSchedule::GROUP_K10),
            'permanentShs' => $schedule->permanent(StudentAttendanceSchedule::GROUP_SHS),
            'temporaryK10' => $schedule->temporary(StudentAttendanceSchedule::GROUP_K10),
            'temporaryShs' => $schedule->temporary(StudentAttendanceSchedule::GROUP_SHS),
            'outAllowedFromLabel' => $schedule->outAllowedFromLabel(),
        ]);
    }

    public function updateScheduleSettings(Request $request, StudentAttendanceSchedule $schedule)
    {
        $validated = $request->validate([
            'groups.k10.in_time' => ['required', 'date_format:H:i'],
            'groups.k10.out_time' => ['required', 'date_format:H:i'],
            'groups.k10.grace_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'groups.shs.in_time' => ['required', 'date_format:H:i'],
            'groups.shs.out_time' => ['required', 'date_format:H:i'],
            'groups.shs.grace_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'temporary.enabled' => ['nullable', 'in:0,1'],
            'temporary.k10.in_time' => ['nullable', 'date_format:H:i'],
            'temporary.k10.out_time' => ['nullable', 'date_format:H:i'],
            'temporary.shs.in_time' => ['nullable', 'date_format:H:i'],
            'temporary.shs.out_time' => ['nullable', 'date_format:H:i'],
            'temporary.starts_on' => ['nullable', 'date'],
            'temporary.ends_on' => ['nullable', 'date', 'after_or_equal:temporary.starts_on'],
        ]);

        $tempEnabled = (string) $request->input('temporary.enabled') === '1';
        if ($tempEnabled) {
            $request->validate([
                'temporary.k10.in_time' => ['required', 'date_format:H:i'],
                'temporary.k10.out_time' => ['required', 'date_format:H:i'],
                'temporary.shs.in_time' => ['required', 'date_format:H:i'],
                'temporary.shs.out_time' => ['required', 'date_format:H:i'],
                'temporary.starts_on' => ['required', 'date'],
                'temporary.ends_on' => ['required', 'date', 'after_or_equal:temporary.starts_on'],
            ]);
        }

        $schedule->update([
            'groups' => [
                'k10' => [
                    'in_time' => $validated['groups']['k10']['in_time'],
                    'out_time' => $validated['groups']['k10']['out_time'],
                    'grace_minutes' => $validated['groups']['k10']['grace_minutes'],
                ],
                'shs' => [
                    'in_time' => $validated['groups']['shs']['in_time'],
                    'out_time' => $validated['groups']['shs']['out_time'],
                    'grace_minutes' => $validated['groups']['shs']['grace_minutes'],
                ],
            ],
            'temporary' => [
                'enabled' => $tempEnabled,
                'starts_on' => $request->input('temporary.starts_on'),
                'ends_on' => $request->input('temporary.ends_on'),
                'k10' => [
                    'in_time' => $request->input('temporary.k10.in_time'),
                    'out_time' => $request->input('temporary.k10.out_time'),
                ],
                'shs' => [
                    'in_time' => $request->input('temporary.shs.in_time'),
                    'out_time' => $request->input('temporary.shs.out_time'),
                ],
            ],
        ]);

        return back()->with('success', 'Attendance schedule saved.');
    }

    public function backfillLateFlags(Request $request, StudentAttendanceSchedule $schedule)
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $result = $schedule->backfillLateFlags(
            $validated['from'] ?? null,
            $validated['to'] ?? null
        );

        return back()->with(
            'success',
            "Late flags updated on {$result['updated']} of {$result['scanned']} IN record(s)."
        );
    }

    public function availableGates(Request $request, GateTerminalService $gates)
    {
        $request->validate([
            'terminal_token' => 'required|string|max:64',
        ]);

        return response()->json([
            'gates' => $gates->availableGatesFor($request->input('terminal_token')),
            'current_gate' => $gates->currentGateFor($request->input('terminal_token')),
        ]);
    }

    public function claimGate(Request $request, GateTerminalService $gates)
    {
        $request->validate([
            'terminal_token' => 'required|string|max:64',
            'gate' => 'required|string|max:120',
        ]);

        $result = $gates->claim(
            $request->input('terminal_token'),
            $request->input('gate'),
        );

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['gate' => $result['gate']]);
    }

    public function pingGate(Request $request, GateTerminalService $gates)
    {
        $request->validate([
            'terminal_token' => 'required|string|max:64',
        ]);

        $gates->ping($request->input('terminal_token'));

        return response()->json(['ok' => true]);
    }

    public function scan(Request $request)
    {
        $request->validate(['qrcode' => 'required|string']);

        $student = $this->resolveStudent($request->qrcode);

        if ($student) {
            return response()->json($this->buildScanResponse($student));
        }

        $visitor = $this->resolveVisitor($request->qrcode);

        if ($visitor) {
            return response()->json($this->buildVisitorScanResponse($visitor));
        }

        return response()->json([
            'type' => 'error',
            'message' => 'ID not recognized. Students and employees use their school ID. Visitors must register first.',
        ]);
    }

    public function identifyByFace(Request $request, FaceMatchService $faces)
    {
        if (! config('face.enabled')) {
            abort(404);
        }

        $request->validate([
            'descriptor' => 'required|array|size:'.config('face.descriptor_length', 128),
            'descriptor.*' => 'numeric',
        ]);

        $match = $faces->findBestMatch($request->input('descriptor'));

        if ($match === null) {
            return response()->json([
                'type' => 'error',
                'message' => 'Face not recognized. Please enroll or try again.',
            ]);
        }

        return response()->json($this->buildScanResponse($match['student']));
    }

    /** @return array<string, mixed> */
    protected function buildScanResponse(Student $student): array
    {
        app(AttendanceSessionService::class)->closeStaleOpenInForStudent($student);

        $lastLog = AttendanceLog::where('student_id', $student->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        if ($cooldown = $this->scanCooldownPayload($student, $lastLog)) {
            return $cooldown;
        }

        $schedule = app(StudentAttendanceSchedule::class);
        $decision = $schedule->dailyScanDecision($student);

        if ($decision['blocked']) {
            if ($decision['type'] === 'out_too_early') {
                return [
                    'type' => 'early_out_blocked',
                    'message' => $decision['message'],
                    'allowed_after' => $decision['allowed_after'],
                    'student' => [
                        'id' => $student->id,
                        'firstname' => $student->firstname,
                        'lastname' => $student->lastname,
                        'profile_picture' => $student->profile_picture,
                        'year' => $student->year,
                        'educational_level' => $student->educational_level?->label()
                            ?? $student->educational_level,
                    ],
                ];
            }

            return [
                'type' => 'error',
                'message' => $decision['message'] ?? 'Scan not allowed.',
                'student' => [
                    'id' => $student->id,
                    'firstname' => $student->firstname,
                    'lastname' => $student->lastname,
                    'profile_picture' => $student->profile_picture,
                ],
            ];
        }

        $departure = app(StudentDeparturePolicy::class);
        if ($decision['next_status'] === 'OUT' && $departure->blocksCheckout($student)) {
            return [
                'type' => 'early_out_blocked',
                'message' => $this->earlyOutMessage($departure),
                'allowed_after' => $departure->earliestOutLabel(),
                'student' => [
                    'id' => $student->id,
                    'firstname' => $student->firstname,
                    'lastname' => $student->lastname,
                    'profile_picture' => $student->profile_picture,
                    'year' => $student->year,
                    'educational_level' => $student->educational_level?->label()
                        ?? $student->educational_level,
                ],
            ];
        }

        return [
            'type' => 'student',
            'next_status' => $decision['next_status'],
            'student_id' => $student->id,
            'logout_feedback_enabled' => $this->effectiveLogoutFeedbackEnabled(),
            'section_picker_enabled' => $this->effectiveSectionPickerEnabled(),
            'student' => [
                'id' => $student->id,
                'firstname' => $student->firstname,
                'lastname' => $student->lastname,
                'profile_picture' => $student->profile_picture,
            ],
        ];
    }

    public function processSection(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'section' => 'nullable|string|max:255',
        ]);

        $section = $request->section ? trim((string) $request->section) : null;
        if ($section !== null && $section !== '') {
            $allowed = Setting::attendanceSections();
            if (! in_array($section, $allowed, true)) {
                return response()->json(['message' => 'Invalid section selected.'], 422);
            }
        } else {
            $section = null;
        }

        $student = Student::findOrFail($request->student_id);
        $sessions = app(AttendanceSessionService::class);
        $sessions->closeStaleOpenInForStudent($student);

        $lastLog = AttendanceLog::where('student_id', $student->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        if ($cooldown = $this->scanCooldownPayload($student, $lastLog)) {
            return response()->json($cooldown, 429);
        }

        $schedule = app(StudentAttendanceSchedule::class);
        $decision = $schedule->dailyScanDecision($student);

        if ($decision['blocked'] || ! $decision['next_status']) {
            $code = $decision['type'] === 'out_too_early' ? 403 : 422;

            return response()->json([
                'message' => $decision['message'] ?? 'Scan not allowed.',
                'allowed_after' => $decision['allowed_after'],
            ], $code);
        }

        $newStatus = $decision['next_status'];

        $departure = app(StudentDeparturePolicy::class);
        if ($newStatus === 'OUT' && $departure->blocksCheckout($student)) {
            return response()->json([
                'message' => $this->earlyOutMessage($departure),
                'allowed_after' => $departure->earliestOutLabel(),
            ], 403);
        }

        $scannedAt = now();
        $isLate = $newStatus === 'IN' && $schedule->isLate($scannedAt, $student);

        $log = AttendanceLog::create([
            'student_id' => $student->id,
            'section' => $section,
            'gate' => null,
            'status' => $newStatus,
            'is_late' => $isLate,
            'scanned_at' => $scannedAt,
        ]);

        try {
            $this->sendScanSms($student, $isLate ? 'LATE' : $newStatus);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'status' => $newStatus,
            'is_late' => $isLate,
            'designation' => $isLate ? 'LATE' : null,
            'scanned_at' => $log->scanned_at->format('Y-m-d h:i:s A'),
            'logout_feedback_enabled' => $this->effectiveLogoutFeedbackEnabled(),
        ]);
    }

    public function processVisitor(Request $request)
    {
        $request->validate([
            'visitor_id' => 'required|integer|exists:visitors,id',
        ]);

        $visitor = Visitor::findOrFail($request->visitor_id);
        $sessions = app(AttendanceSessionService::class);
        $sessions->closeStaleOpenInForVisitor($visitor);

        $lastLog = VisitorLog::where('visitor_id', $visitor->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        $newStatus = ($lastLog && $sessions->isInStatus($lastLog->status)) ? 'OUT' : 'IN';

        $log = VisitorLog::create([
            'visitor_id' => $visitor->id,
            'status' => $newStatus,
            'gate' => null,
            'scanned_at' => now(),
        ]);

        return response()->json([
            'status' => $newStatus,
            'scanned_at' => $log->scanned_at->format('Y-m-d h:i:s A'),
        ]);
    }

    /** @return array<string, mixed> */
    protected function buildVisitorScanResponse(Visitor $visitor): array
    {
        app(AttendanceSessionService::class)->closeStaleOpenInForVisitor($visitor);

        $sessions = app(AttendanceSessionService::class);
        $lastLog = VisitorLog::where('visitor_id', $visitor->id)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        $nextStatus = ($lastLog && $sessions->isInStatus($lastLog->status)) ? 'OUT' : 'IN';

        return [
            'type' => 'visitor',
            'next_status' => $nextStatus,
            'visitor_id' => $visitor->id,
            'visitor' => [
                'id' => $visitor->id,
                'firstname' => $visitor->firstname,
                'lastname' => $visitor->lastname,
                'organization' => $visitor->organization,
            ],
        ];
    }

    private function resolveVisitor(string $raw): ?Visitor
    {
        $token = trim(str_replace("\r", '', $raw));

        if ($token === '') {
            return null;
        }

        return Visitor::where('qrcode', $token)->first();
    }

    public function showChangeVideo()
    {
        return view('attendance.change_video');
    }

    public function uploadVideo(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimes:mp4|max:512000',
        ]);

        $video = $request->file('video');
        $filename = 'area51_product_slideshow.mp4';
        $video->move(base_path('videos'), $filename);

        return redirect()->route('attendance.changeVideo')->with('success', 'Video uploaded successfully!');
    }

    private function earlyOutMessage(StudentDeparturePolicy $departure): string
    {
        return str_replace(
            '{time}',
            $departure->earliestOutLabel(),
            $departure->blockMessage()
        );
    }

    /** @return array<string, mixed>|null */
    private function scanCooldownPayload(Student $student, ?AttendanceLog $lastLog): ?array
    {
        $minutes = (int) config('attendance.scan_cooldown_minutes', 10);
        if ($minutes <= 0 || ! $lastLog?->scanned_at) {
            return null;
        }

        $elapsedSeconds = $lastLog->scanned_at->diffInSeconds(now(), false);
        if ($elapsedSeconds < 0) {
            return null;
        }

        $cooldownSeconds = $minutes * 60;
        if ($elapsedSeconds >= $cooldownSeconds) {
            return null;
        }

        $waitMinutes = (int) max(1, (int) ceil(($cooldownSeconds - $elapsedSeconds) / 60));

        return [
            'type' => 'scan_cooldown',
            'message' => 'Please wait '.$waitMinutes.' more minute'.($waitMinutes === 1 ? '' : 's').' before scanning again.',
            'retry_after_minutes' => $waitMinutes,
            'cooldown_minutes' => $minutes,
            'student' => [
                'id' => $student->id,
                'firstname' => $student->firstname,
                'lastname' => $student->lastname,
                'profile_picture' => $student->profile_picture,
                'year' => $student->year,
            ],
        ];
    }

    private function resolveStudent(string $raw): ?Student
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

    private function parseQr(string $raw): array
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

    private function sendScanSms(Student $student, string $status): void
    {
        $recipient = trim((string) ($student->emergency_number ?? ''));
        if ($recipient === '') {
            \Illuminate\Support\Facades\Log::warning('Scan SMS skip: student has no emergency_number', [
                'student_id' => $student->id,
            ]);

            return;
        }

        $template = Setting::scanSmsTemplateForStatus($status);

        $message = $student->fillSmsTemplate($template, [
            '{status}' => $status,
            '{time}' => Carbon::now('Asia/Manila')->format('h:i A'),
        ]);

        app(SmsController::class)->sendDirect(
            $recipient,
            $message,
            'scan',
            SmsLog::studentMeta($student, strtoupper($status) === 'OUT' ? 'gate_departure' : 'gate_arrival')
        );
    }
}
