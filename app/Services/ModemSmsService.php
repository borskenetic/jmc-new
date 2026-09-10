<?php

namespace App\Services;

use App\Jobs\SendModemSmsJob;
use App\Models\SmsLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Modem SMS delivery with optional pending retry when the queue is full / unreachable.
 * Adapted to jmc sms_logs (recipient, source, status sent|pending|failed|skipped, meta).
 */
class ModemSmsService
{
    /**
     * @param  array{
     *   source?: string,
     *   meta?: array<string, mixed>|null,
     *   user_id?: int|null
     * }  $context
     */
    public function send(string $number, string $message, array $context = []): bool
    {
        return $this->deliver($number, $message, $context, withRetry: false) === 'sent';
    }

    /**
     * Gate / scan path: try once, then keep retrying on modem queue-full / connection errors.
     *
     * @param  array{
     *   source?: string,
     *   meta?: array<string, mixed>|null,
     *   user_id?: int|null
     * }  $context
     */
    public function sendWithRetry(string $number, string $message, array $context = []): bool
    {
        return $this->deliver($number, $message, $context, withRetry: true) === 'sent';
    }

    /**
     * @return 'sent'|'pending'|'failed'|'skipped'
     */
    protected function deliver(string $number, string $message, array $context, bool $withRetry): string
    {
        $source = (string) ($context['source'] ?? 'direct');
        $meta = is_array($context['meta'] ?? null) ? $context['meta'] : null;
        $userId = $context['user_id'] ?? Auth::id();
        $type = (string) (($meta['type'] ?? null) ?: $source);
        $studentId = isset($meta['student_id']) && is_numeric($meta['student_id'])
            ? (int) $meta['student_id']
            : null;

        $normalized = $number;
        if ($normalized === '') {
            $this->writeLog(
                recipient: $number,
                message: $message,
                status: SmsLog::STATUS_SKIPPED,
                source: $source,
                error: 'Missing or invalid mobile number',
                meta: $meta,
                userId: $userId,
            );

            return 'skipped';
        }

        if ($withRetry && $studentId !== null && $type !== '') {
            $existingPending = SmsLog::query()
                ->where('status', SmsLog::STATUS_PENDING)
                ->where('created_at', '>=', now()->subDay())
                ->where('meta->student_id', $studentId)
                ->where('meta->type', $type)
                ->where('recipient', substr($normalized, 0, 32))
                ->orderByDesc('id')
                ->first();

            if ($existingPending) {
                return 'pending';
            }
        }

        $url = config('services.sms_modem.url') ?: env('SMS_MODEM_URL');
        $apiKey = config('services.sms_modem.key') ?: env('SMS_MODEM_API_KEY');

        if (! $url) {
            Log::warning('SMS skip: SMS_MODEM_URL is empty');
            $this->writeLog(
                recipient: $normalized,
                message: $message,
                status: SmsLog::STATUS_SKIPPED,
                source: $source,
                error: 'SMS_MODEM_URL is not configured',
                meta: $meta,
                userId: $userId,
            );

            return 'skipped';
        }

        try {
            Log::info('SMS POST', ['url' => $url, 'number' => $normalized]);

            $response = $this->postToModem($url, $apiKey, [
                ['number' => $normalized, 'message' => $message],
            ], 30);

            $httpMeta = array_merge($meta ?? [], ['http_status' => $response->status()]);

            if ($response->successful()) {
                $this->writeLog(
                    recipient: $normalized,
                    message: $message,
                    status: SmsLog::STATUS_SENT,
                    source: $source,
                    error: null,
                    meta: $httpMeta,
                    userId: $userId,
                );

                return 'sent';
            }

            $error = $this->formatHttpError($response);

            if ($withRetry && $this->isRetryableHttpStatus($response->status())) {
                $log = $this->writeLog(
                    recipient: $normalized,
                    message: $message,
                    status: SmsLog::STATUS_PENDING,
                    source: $source,
                    error: $error.' — queued for retry',
                    meta: $this->retryMeta($httpMeta, attempt: 1),
                    userId: $userId,
                );

                if ($log) {
                    $this->dispatchRetry($log, $this->retryDelaySeconds(1));

                    return 'pending';
                }

                return 'failed';
            }

            Log::warning('SMS server non-success', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            $this->writeLog(
                recipient: $normalized,
                message: $message,
                status: SmsLog::STATUS_FAILED,
                source: $source,
                error: $error,
                meta: $httpMeta,
                userId: $userId,
            );

            return 'failed';
        } catch (\Throwable $e) {
            Log::error('SMS POST failed', ['url' => $url, 'error' => $e->getMessage()]);
            report($e);

            if ($withRetry && $this->isRetryableException($e)) {
                $log = $this->writeLog(
                    recipient: $normalized,
                    message: $message,
                    status: SmsLog::STATUS_PENDING,
                    source: $source,
                    error: $e->getMessage().' — queued for retry',
                    meta: $this->retryMeta($meta, attempt: 1),
                    userId: $userId,
                );

                if ($log) {
                    $this->dispatchRetry($log, $this->retryDelaySeconds(1));

                    return 'pending';
                }

                return 'failed';
            }

            $this->writeLog(
                recipient: $normalized,
                message: $message,
                status: SmsLog::STATUS_FAILED,
                source: $source,
                error: $e->getMessage(),
                meta: $meta,
                userId: $userId,
            );

            return 'failed';
        }
    }

    /**
     * Re-attempt a pending (or requeued failed) log. Updates the same sms_logs row.
     *
     * @return 'sent'|'retry'|'give_up'|'skipped'
     */
    public function attemptPendingDelivery(SmsLog $log): string
    {
        $lock = Cache::lock('sms-retry-'.$log->id, 45);
        if (! $lock->get()) {
            return 'retry';
        }

        try {
            $log->refresh();

            if ($log->status === SmsLog::STATUS_SENT) {
                return 'sent';
            }

            if ($log->status === SmsLog::STATUS_SKIPPED) {
                return 'skipped';
            }

            if (! in_array($log->status, [SmsLog::STATUS_PENDING, SmsLog::STATUS_FAILED], true)) {
                return 'give_up';
            }

            $meta = is_array($log->meta) ? $log->meta : [];
            $attempt = (int) ($meta['retry_attempt'] ?? 0);
            $maxAttempts = max(1, (int) config('services.sms_modem.retry_max_attempts', 60));

            if ($attempt >= $maxAttempts) {
                $log->update([
                    'status' => SmsLog::STATUS_FAILED,
                    'error' => trim((string) $log->error)." — gave up after {$maxAttempts} attempts",
                    'meta' => array_merge($meta, ['gave_up_at' => now()->toIso8601String()]),
                ]);

                return 'give_up';
            }

            $number = (string) ($log->recipient ?? '');
            $message = (string) $log->message;
            if ($number === '' || $message === '') {
                $log->update([
                    'status' => SmsLog::STATUS_SKIPPED,
                    'error' => 'Missing number or message',
                ]);

                return 'skipped';
            }

            $url = config('services.sms_modem.url') ?: env('SMS_MODEM_URL');
            $apiKey = config('services.sms_modem.key') ?: env('SMS_MODEM_API_KEY');

            if (! $url) {
                $log->update([
                    'status' => SmsLog::STATUS_FAILED,
                    'error' => 'SMS modem URL is not configured (SMS_MODEM_URL)',
                ]);

                return 'give_up';
            }

            $nextAttempt = $attempt + 1;

            try {
                $response = $this->postToModem($url, $apiKey, [
                    ['number' => $number, 'message' => $message],
                ], 30);

                if ($response->successful()) {
                    $log->update([
                        'status' => SmsLog::STATUS_SENT,
                        'error' => null,
                        'meta' => array_merge($this->retryMeta($meta, attempt: $nextAttempt), [
                            'http_status' => $response->status(),
                            'delivered_at' => now()->toIso8601String(),
                        ]),
                    ]);

                    return 'sent';
                }

                $error = $this->formatHttpError($response);

                if ($this->isRetryableHttpStatus($response->status()) && $nextAttempt < $maxAttempts) {
                    $log->update([
                        'status' => SmsLog::STATUS_PENDING,
                        'error' => $error.' — retrying',
                        'meta' => array_merge($this->retryMeta($meta, attempt: $nextAttempt), [
                            'http_status' => $response->status(),
                        ]),
                    ]);

                    return 'retry';
                }

                $log->update([
                    'status' => SmsLog::STATUS_FAILED,
                    'error' => $error,
                    'meta' => array_merge($this->retryMeta($meta, attempt: $nextAttempt), [
                        'http_status' => $response->status(),
                    ]),
                ]);

                return 'give_up';
            } catch (\Throwable $e) {
                report($e);

                if ($this->isRetryableException($e) && $nextAttempt < $maxAttempts) {
                    $log->update([
                        'status' => SmsLog::STATUS_PENDING,
                        'error' => $e->getMessage().' — retrying',
                        'meta' => $this->retryMeta($meta, attempt: $nextAttempt),
                    ]);

                    return 'retry';
                }

                $log->update([
                    'status' => SmsLog::STATUS_FAILED,
                    'error' => $e->getMessage(),
                    'meta' => $this->retryMeta($meta, attempt: $nextAttempt),
                ]);

                return 'give_up';
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{sent: int, retry: int, give_up: int, skipped: int}
     */
    public function processDueRetries(?int $limit = null, bool $includeFailedRetryable = false): array
    {
        $limit ??= max(1, (int) config('services.sms_modem.retry_batch_size', 40));

        $logs = SmsLog::query()
            ->where('status', SmsLog::STATUS_PENDING)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($includeFailedRetryable && $logs->count() < $limit) {
            $maxAttempts = max(1, (int) config('services.sms_modem.retry_max_attempts', 60));

            $extra = SmsLog::query()
                ->where('status', SmsLog::STATUS_FAILED)
                ->where(function ($q) {
                    $q->where('meta->http_status', 503)
                        ->orWhere('error', 'like', '%queue is full%')
                        ->orWhere('error', 'like', '%Connection%')
                        ->orWhere('error', 'like', '%HTTP 503%');
                })
                ->where(function ($q) {
                    $q->whereNull('error')
                        ->orWhere('error', 'not like', '%gave up%');
                })
                ->where('created_at', '>=', now()->subDay())
                ->orderBy('id')
                ->limit($limit - $logs->count())
                ->get()
                ->filter(function (SmsLog $log) use ($maxAttempts) {
                    $meta = is_array($log->meta) ? $log->meta : [];
                    $attempt = (int) ($meta['retry_attempt'] ?? 0);

                    return $attempt < $maxAttempts;
                });

            foreach ($extra as $log) {
                $meta = is_array($log->meta) ? $log->meta : [];
                $log->update([
                    'status' => SmsLog::STATUS_PENDING,
                    'error' => trim((string) preg_replace('/\s*—\s*requeued$/u', '', (string) $log->error)).' — requeued',
                    'meta' => $this->retryMeta($meta, attempt: (int) ($meta['retry_attempt'] ?? 0)),
                ]);
            }

            $logs = $logs->concat($extra->values());
        }

        $counts = ['sent' => 0, 'retry' => 0, 'give_up' => 0, 'skipped' => 0];

        foreach ($logs as $log) {
            $result = $this->attemptPendingDelivery($log);
            if ($result === 'retry') {
                $log->refresh();
                $attempt = (int) ((is_array($log->meta) ? $log->meta['retry_attempt'] : null) ?? 1);
                $this->dispatchRetry($log, $this->retryDelaySeconds($attempt));
                $counts['retry']++;
            } elseif (isset($counts[$result])) {
                $counts[$result]++;
            }
        }

        return $counts;
    }

    public function dispatchRetry(SmsLog $log, int $delaySeconds = 20): void
    {
        SendModemSmsJob::dispatch($log->id)
            ->onConnection('database')
            ->delay(now()->addSeconds(max(5, $delaySeconds)));
    }

    public function retryDelaySeconds(int $attempt): int
    {
        $base = max(5, (int) config('services.sms_modem.retry_base_seconds', 20));
        $cap = max($base, (int) config('services.sms_modem.retry_max_seconds', 180));
        $exp = min(max(0, $attempt - 1), 4);

        return (int) min($cap, $base * (2 ** $exp));
    }

    public function isRetryableHttpStatus(?int $status): bool
    {
        return in_array($status, [408, 429, 500, 502, 503, 504], true);
    }

    public function isRetryableException(\Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || str_contains(strtolower($e->getMessage()), 'connection')
            || str_contains(strtolower($e->getMessage()), 'timed out');
    }

    /**
     * @param  list<array{number: string, message: string}>  $payload
     */
    private function postToModem(string $url, ?string $apiKey, array $payload, int $timeout)
    {
        return Http::withHeaders([
            'X-API-KEY' => $apiKey,
            'ngrok-skip-browser-warning' => 'true',
        ])
            ->timeout($timeout)
            ->retry(4, 800, function ($exception) {
                return $exception instanceof ConnectionException;
            }, throw: false)
            ->post($url, $payload);
    }

    private function formatHttpError(Response $response): string
    {
        $body = $response->body();

        return 'HTTP '.$response->status()
            .($body !== '' ? ': '.mb_substr($body, 0, 300) : '');
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    private function retryMeta(?array $meta, int $attempt): array
    {
        $meta = is_array($meta) ? $meta : [];

        return array_merge($meta, [
            'retry_attempt' => $attempt,
            'retryable' => true,
            'last_retry_at' => now()->toIso8601String(),
        ]);
    }

    private function writeLog(
        string $recipient,
        string $message,
        string $status,
        string $source,
        ?string $error,
        ?array $meta,
        mixed $userId,
    ): ?SmsLog {
        try {
            return SmsLog::create([
                'user_id' => is_numeric($userId) ? (int) $userId : null,
                'recipient' => substr($recipient, 0, 32),
                'message' => $message,
                'status' => $status,
                'source' => $source,
                'error' => $error ? substr($error, 0, 500) : null,
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
