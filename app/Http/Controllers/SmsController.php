<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\Student;
use App\Services\ModemSmsService;
use App\Support\ActivityLogger;
use App\Support\PatronOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsController extends Controller
{
    public const DEFAULT_SCAN_SMS = '{name} has scanned {status} into the campus at {time}.';

    public function index()
    {
        $yearOptions = PatronOptions::allYearOptions();

        $dbYears = Student::query()
            ->whereNotNull('year')
            ->where('year', '!=', '')
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->all();

        foreach ($dbYears as $year) {
            if (! in_array($year, $yearOptions, true)) {
                $yearOptions[] = $year;
            }
        }

        $courses = Student::select('course')
            ->whereNotNull('course')
            ->where('course', '!=', '')
            ->distinct()
            ->orderBy('course')
            ->pluck('course');

        $sections = Student::query()
            ->whereNotNull('section')
            ->where('section', '!=', '')
            ->distinct()
            ->orderBy('section')
            ->pluck('section');

        $sectionsByGrade = Student::query()
            ->whereNotNull('section')
            ->where('section', '!=', '')
            ->whereNotNull('year')
            ->where('year', '!=', '')
            ->get(['year', 'section'])
            ->groupBy('year')
            ->map(fn ($rows) => $rows->pluck('section')->unique()->sort()->values()->all())
            ->all();

        return view('sms.blast', [
            'courses' => $courses,
            'yearOptions' => $yearOptions,
            'sections' => $sections,
            'sectionsByGrade' => $sectionsByGrade,
            'simLoad' => Setting::smsSimLoad(),
        ]);
    }

    public function updateSimLoad(Request $request)
    {
        $validated = $request->validate([
            'loaded_at' => 'required|date',
            'validity_days' => 'required|integer|min:1|max:365',
        ]);

        Setting::setSmsSimLoad(
            \Carbon\Carbon::parse($validated['loaded_at'])->toDateString(),
            (int) $validated['validity_days']
        );

        return back()->with('success', 'SIM load updated.');
    }

    public function scanMessage()
    {
        return view('sms.scan_message', [
            'arrival' => Setting::scanSmsArrivalTemplate(),
            'departure' => Setting::scanSmsDepartureTemplate(),
            'scanSmsEnabled' => Setting::scanSmsEventsEnabled(),
        ]);
    }

    public function updateScanMessage(Request $request)
    {
        $request->validate([
            'arrival' => 'required|string',
            'departure' => 'required|string',
            'arrival_enabled' => 'nullable|in:0,1',
            'departure_enabled' => 'nullable|in:0,1',
        ]);

        Setting::updateOrCreate(
            ['key' => Setting::KEY_SCAN_SMS_ARRIVAL],
            ['value' => $request->arrival]
        );
        Setting::updateOrCreate(
            ['key' => Setting::KEY_SCAN_SMS_DEPARTURE],
            ['value' => $request->departure]
        );
        Setting::updateOrCreate(
            ['key' => Setting::KEY_SCAN_SMS],
            ['value' => $request->arrival]
        );

        Setting::setScanSmsEventEnabled('arrival', $request->input('arrival_enabled', '0') === '1');
        Setting::setScanSmsEventEnabled('departure', $request->input('departure_enabled', '0') === '1');

        return back()->with('success', 'Gate SMS templates updated.');
    }

    public function count(Request $request)
    {
        $request->validate([
            'recipient' => 'nullable|in:student,emergency_contact',
            'year' => 'nullable|string',
            'course' => 'nullable|string',
            'section' => 'nullable|string',
        ]);

        return response()->json([
            'count' => $this->blastQuery($request)->count(),
        ]);
    }

    public function send(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'recipient' => 'required|in:student,emergency_contact',
            'year' => 'nullable|string',
            'course' => 'nullable|string',
            'section' => 'nullable|string',
        ]);

        $column = $this->recipientColumn($request->input('recipient'));
        $students = $this->blastQuery($request)->get();

        if ($students->isEmpty()) {
            return back()->with('error', 'No recipients found for the selected filters.');
        }

        $url = config('services.sms_modem.url');
        $apiKey = config('services.sms_modem.key');

        if (! $url) {
            return back()->with('error', 'SMS modem URL is not configured (SMS_MODEM_URL).');
        }

        $payload = [];
        $entries = [];

        foreach ($students as $student) {
            $message = $student->fillSmsTemplate($request->message);
            $rawNumber = (string) ($student->{$column} ?? '');
            $numbers = $this->normalizePhilippineMobiles($rawNumber);
            $meta = array_merge(SmsLog::studentMeta($student, 'blast'), [
                'recipient_type' => $request->input('recipient'),
            ]);

            if ($numbers === []) {
                $this->recordSmsLog(
                    recipient: $rawNumber,
                    message: $message,
                    status: 'skipped',
                    source: 'blast',
                    error: 'Invalid mobile number',
                    meta: $meta,
                );

                continue;
            }

            foreach ($numbers as $number) {
                $payload[] = [
                    'number' => $number,
                    'message' => $message,
                ];

                $entries[] = [
                    'number' => $number,
                    'message' => $message,
                    'student_id' => $student->id,
                    'meta' => $meta,
                ];
            }
        }

        if ($payload === []) {
            return back()->with('error', 'No valid mobile numbers among the selected recipients.');
        }

        $label = $request->input('recipient') === 'student'
            ? 'student mobile numbers'
            : 'emergency contacts';

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'ngrok-skip-browser-warning' => 'true',
            ])->timeout(300)
                ->post($url, $payload);

            $ok = $response->successful();
            $error = $ok ? null : ('HTTP '.$response->status().': '.substr($response->body(), 0, 200));
            $httpStatus = $response->status();

            foreach ($entries as $entry) {
                $this->recordSmsLog(
                    recipient: $entry['number'],
                    message: $entry['message'],
                    status: $ok ? 'sent' : 'failed',
                    source: 'blast',
                    error: $error,
                    meta: array_merge($entry['meta'] ?? [], ['http_status' => $httpStatus]),
                );
            }

            ActivityLogger::log(
                action: 'sms.blast',
                description: sprintf(
                    'SMS blast to %d %s — %s',
                    count($entries),
                    $label,
                    $ok ? 'sent' : 'failed'
                ),
                properties: [
                    'recipients' => count($entries),
                    'success' => $ok,
                    'recipient_type' => $request->input('recipient'),
                    'year' => $request->year,
                    'section' => $request->section,
                    'course' => $request->course,
                ],
            );

            if (! $ok) {
                return back()->with('error', 'SMS server rejected the request (HTTP '.$response->status().').');
            }

            return back()->with('success', 'SMS sent successfully to '.count($entries).' '.$label.'.');
        } catch (\Throwable $e) {
            Log::error('SMS blast failed', ['error' => $e->getMessage()]);
            report($e);

            foreach ($entries as $entry) {
                $this->recordSmsLog(
                    recipient: $entry['number'],
                    message: $entry['message'],
                    status: 'failed',
                    source: 'blast',
                    error: $e->getMessage(),
                    meta: $entry['meta'] ?? ['student_id' => $entry['student_id']],
                );
            }

            return back()->with('error', 'Could not reach the SMS server: '.$e->getMessage());
        }
    }

    private function recipientColumn(string $recipient): string
    {
        return $recipient === 'student' ? 'mobile_number' : 'emergency_number';
    }

    private function blastQuery(Request $request)
    {
        $recipient = $request->input('recipient', 'emergency_contact');
        $column = $this->recipientColumn(
            in_array($recipient, ['student', 'emergency_contact'], true)
                ? $recipient
                : 'emergency_contact'
        );

        $query = Student::query()
            ->whereNotNull($column)
            ->where($column, '!=', '');

        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        if ($request->filled('course')) {
            $query->where('course', $request->course);
        }

        if ($request->filled('section')) {
            $query->where('section', $request->section);
        }

        return $query;
    }

    public function sendDirect(string $number, string $message, string $source = 'direct', ?array $meta = null): bool
    {
        $numbers = $this->normalizePhilippineMobiles($number);

        if ($numbers === []) {
            Log::warning('SMS skip: empty/invalid mobile number after normalize', [
                'raw' => $number,
            ]);
            $this->recordSmsLog(
                recipient: $number,
                message: $message,
                status: SmsLog::STATUS_SKIPPED,
                source: $source,
                error: 'Invalid mobile number',
                meta: $meta,
            );

            return false;
        }

        $modem = app(ModemSmsService::class);
        $anySent = false;

        foreach ($numbers as $normalized) {
            // Gate / scan SMS retry when the modem queue is full; other callers get a single attempt.
            $ok = $source === 'scan'
                ? $modem->sendWithRetry($normalized, $message, [
                    'source' => $source,
                    'meta' => $meta,
                    'user_id' => Auth::id(),
                ])
                : $modem->send($normalized, $message, [
                    'source' => $source,
                    'meta' => $meta,
                    'user_id' => Auth::id(),
                ]);

            if ($ok) {
                $anySent = true;
            }
        }

        return $anySent;
    }

    private function recordSmsLog(
        string $recipient,
        string $message,
        string $status,
        string $source,
        ?string $error = null,
        ?array $meta = null,
    ): void {
        try {
            SmsLog::create([
                'user_id' => Auth::id(),
                'recipient' => substr($recipient, 0, 32),
                'message' => $message,
                'status' => $status,
                'source' => $source,
                'error' => $error ? substr($error, 0, 500) : null,
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Split free-form contact fields into unique E.164 PH mobiles (+639XXXXXXXXX).
     * Accepts separators like /, ,, ;, | and formats such as:
     * 09102243526, 0910-224-3526, +639102243526, 639102243526, 9102243526.
     *
     * @return list<string>
     */
    public function normalizePhilippineMobiles(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:\/|,|;|\||(?:\band\b))\s*/i', $raw) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            $number = $this->normalizePhilippineMobile(trim((string) $part));
            if ($number !== '' && ! in_array($number, $normalized, true)) {
                $normalized[] = $number;
            }
        }

        return $normalized;
    }

    private function normalizePhilippineMobile(string $number): string
    {
        $number = trim($number);
        if ($number === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if ($digits === '') {
            return '';
        }

        // 639XXXXXXXXX or 6309XXXXXXXX (mis-typed with trunk 0)
        if (str_starts_with($digits, '63')) {
            $rest = substr($digits, 2);
            if (str_starts_with($rest, '0')) {
                $rest = substr($rest, 1);
            }
            if (strlen($rest) === 10 && str_starts_with($rest, '9')) {
                return '+63'.$rest;
            }

            return '';
        }

        // 09XXXXXXXXX
        if (str_starts_with($digits, '0')) {
            $rest = substr($digits, 1);
            if (strlen($rest) === 10 && str_starts_with($rest, '9')) {
                return '+63'.$rest;
            }

            return '';
        }

        // 9XXXXXXXXX (no trunk 0)
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '+63'.$digits;
        }

        return '';
    }
}
