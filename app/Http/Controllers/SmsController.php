<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\Student;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsController extends Controller
{
    public function index()
    {
        $courses = Student::select('course')
            ->whereNotNull('course')
            ->where('course', '!=', '')
            ->distinct()
            ->orderBy('course')
            ->pluck('course');

        return view('sms.blast', [
            'courses' => $courses,
        ]);
    }

    public const DEFAULT_SCAN_SMS = '{name} has scanned {status} into the campus at {time}.';

    public function scanMessage()
    {
        $setting = Setting::where('key', 'scan_sms')->first();

        return view('sms.scan_message', [
            'message' => $setting ? $setting->value : self::DEFAULT_SCAN_SMS,
        ]);
    }

    public function updateScanMessage(Request $request)
    {
        $request->validate([
            'message' => 'required',
        ]);

        Setting::updateOrCreate(
            ['key' => 'scan_sms'],
            ['value' => $request->message]
        );

        return back()->with('success', 'Scan SMS updated');
    }

    public function count(Request $request)
    {
        $query = Student::whereNotNull('mobile_number')
            ->where('mobile_number', '!=', '');

        if ($request->year) {
            $query->where('year', $request->year);
        }

        if ($request->course) {
            $query->where('course', $request->course);
        }

        return response()->json([
            'count' => $query->count(),
        ]);
    }

    public function send(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'year' => 'nullable|string',
            'course' => 'nullable|string',
        ]);

        $query = Student::whereNotNull('mobile_number')
            ->where('mobile_number', '!=', '');

        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        if ($request->filled('course')) {
            $query->where('course', $request->course);
        }

        $students = $query->get();

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
            $name = trim(($student->firstname ?? '').' '.($student->lastname ?? ''));
            $message = str_replace('{name}', $name, $request->message);
            $numbers = $this->normalizePhilippineMobiles((string) $student->mobile_number);

            if ($numbers === []) {
                $this->recordSmsLog(
                    recipient: (string) $student->mobile_number,
                    message: $message,
                    status: 'skipped',
                    source: 'blast',
                    error: 'Invalid mobile number',
                    meta: ['student_id' => $student->id],
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
                ];
            }
        }

        if ($payload === []) {
            return back()->with('error', 'No valid mobile numbers among the selected recipients.');
        }

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'ngrok-skip-browser-warning' => 'true',
            ])->timeout(300)
                ->post($url, $payload);

            $ok = $response->successful();
            $error = $ok ? null : ('HTTP '.$response->status().': '.substr($response->body(), 0, 200));

            foreach ($entries as $entry) {
                $this->recordSmsLog(
                    recipient: $entry['number'],
                    message: $entry['message'],
                    status: $ok ? 'sent' : 'failed',
                    source: 'blast',
                    error: $error,
                    meta: ['student_id' => $entry['student_id']],
                );
            }

            ActivityLogger::log(
                action: 'sms.blast',
                description: sprintf(
                    'SMS blast to %d recipient(s) — %s',
                    count($entries),
                    $ok ? 'sent' : 'failed'
                ),
                properties: [
                    'recipients' => count($entries),
                    'success' => $ok,
                    'year' => $request->year,
                    'course' => $request->course,
                ],
            );

            if (! $ok) {
                return back()->with('error', 'SMS server rejected the request (HTTP '.$response->status().').');
            }

            return back()->with('success', 'SMS sent successfully to '.count($entries).' recipient(s).');
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
                    meta: ['student_id' => $entry['student_id']],
                );
            }

            return back()->with('error', 'Could not reach the SMS server: '.$e->getMessage());
        }
    }

    public function sendDirect(string $number, string $message, string $source = 'direct'): bool
    {
        $numbers = $this->normalizePhilippineMobiles($number);

        if ($numbers === []) {
            Log::warning('SMS skip: empty/invalid mobile number after normalize', [
                'raw' => $number,
            ]);
            $this->recordSmsLog(
                recipient: $number,
                message: $message,
                status: 'skipped',
                source: $source,
                error: 'Invalid mobile number',
            );

            return false;
        }

        $url = config('services.sms_modem.url');
        $apiKey = config('services.sms_modem.key');

        if (! $url) {
            Log::warning('SMS skip: SMS_MODEM_URL is empty. Set it in .env to your ngrok /send-sms URL, then php artisan config:clear');
            foreach ($numbers as $normalized) {
                $this->recordSmsLog(
                    recipient: $normalized,
                    message: $message,
                    status: 'skipped',
                    source: $source,
                    error: 'SMS_MODEM_URL is not configured',
                );
            }

            return false;
        }

        $payload = array_map(
            fn (string $normalized) => ['number' => $normalized, 'message' => $message],
            $numbers
        );

        try {
            Log::info('SMS POST', ['url' => $url, 'numbers' => $numbers]);

            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'ngrok-skip-browser-warning' => 'true',
            ])
                ->timeout(30)
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::warning('SMS server non-success', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                foreach ($numbers as $normalized) {
                    $this->recordSmsLog(
                        recipient: $normalized,
                        message: $message,
                        status: 'failed',
                        source: $source,
                        error: 'HTTP '.$response->status(),
                    );
                }

                return false;
            }

            foreach ($numbers as $normalized) {
                $this->recordSmsLog(
                    recipient: $normalized,
                    message: $message,
                    status: 'sent',
                    source: $source,
                );
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('SMS POST failed', ['url' => $url, 'error' => $e->getMessage()]);
            report($e);

            foreach ($numbers as $normalized) {
                $this->recordSmsLog(
                    recipient: $normalized,
                    message: $message,
                    status: 'failed',
                    source: $source,
                    error: $e->getMessage(),
                );
            }

            return false;
        }
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
