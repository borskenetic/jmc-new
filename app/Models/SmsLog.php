<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'user_id',
        'recipient',
        'message',
        'status',
        'source',
        'error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function studentMeta(\App\Models\Student $student, string $type): array
    {
        return [
            'student_id' => $student->id,
            'student_name' => $student->smsFullName(),
            'contact_name' => $student->smsContactName(),
            'type' => $type,
        ];
    }

    public function typeLabel(): string
    {
        $type = (string) (($this->meta['type'] ?? null) ?: $this->source);

        return match ($type) {
            'morning_in' => 'Morning in',
            'gate_arrival', 'scan' => 'Gate arrival',
            'gate_departure' => 'Gate departure',
            'missed_eod' => 'Missed EOD',
            'blast' => 'SMS blast',
            'direct' => 'Direct',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    public function contactName(): string
    {
        return trim((string) ($this->meta['contact_name'] ?? ''));
    }

    public function studentName(): string
    {
        return trim((string) ($this->meta['student_name'] ?? ''));
    }

    public function httpStatusCode(): ?int
    {
        $code = $this->meta['http_status'] ?? null;

        return is_numeric($code) ? (int) $code : null;
    }

    public function statusLabel(): string
    {
        $status = strtolower((string) $this->status);
        $http = $this->httpStatusCode();

        $label = match ($status) {
            'sent' => 'Success',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
            default => ucfirst($status),
        };

        if ($status === 'sent' && $http) {
            return $label.' (HTTP '.$http.')';
        }

        return $label;
    }
}
