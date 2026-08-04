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

    public function scanMessage()
    {
        $setting = Setting::where('key', 'scan_sms')->first();

        return view('sms.scan_message', [
            'message' => $setting ? $setting->value : 'Hello {name}, you scanned {status} at the library.',
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
            $number = $this->normalizePhilippineMobile((string) $student->mobile_number);

            if ($number === '') {
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
        $normalized = $this->normalizePhilippineMobile($number);

        if ($normalized === '') {
            Log::warning('SMS skip: empty/invalid mobile number after normalize');
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
            $this->recordSmsLog(
                recipient: $normalized,
                message: $message,
                status: 'skipped',
                source: $source,
                error: 'SMS_MODEM_URL is not configured',
            );

            return false;
        }

        try {
            Log::info('SMS POST', ['url' => $url, 'number' => $normalized]);

            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'ngrok-skip-browser-warning' => 'true',
            ])
                ->timeout(30)
                ->post($url, [
                    ['number' => $normalized, 'message' => $message],
                ]);

            if (! $response->successful()) {
                Log::warning('SMS server non-success', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                $this->recordSmsLog(
                    recipient: $normalized,
                    message: $message,
                    status: 'failed',
                    source: $source,
                    error: 'HTTP '.$response->status(),
                );

                return false;
            }

            $this->recordSmsLog(
                recipient: $normalized,
                message: $message,
                status: 'sent',
                source: $source,
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('SMS POST failed', ['url' => $url, 'error' => $e->getMessage()]);
            report($e);

            $this->recordSmsLog(
                recipient: $normalized,
                message: $message,
                status: 'failed',
                source: $source,
                error: $e->getMessage(),
            );

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

    private function normalizePhilippineMobile(string $number): string
    {
        $number = preg_replace('/\s+/', '', $number) ?? '';

        if ($number === '') {
            return '';
        }

        if (str_starts_with($number, '0')) {
            return '+63'.substr($number, 1);
        }

        if (str_starts_with($number, '63')) {
            return '+'.$number;
        }

        return $number;
    }
}
