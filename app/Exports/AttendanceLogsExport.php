<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
class AttendanceLogsExport implements FromCollection, WithHeadings
{
    protected $logs;

    // Accept filtered logs from controller
    public function __construct($logs)
    {
        $this->logs = $logs;
    }

    public function collection()
    {
        return $this->logs->map(function ($log) {
            return [
                'lastname'    => $log->student->lastname ?? 'Unknown',
                'firstname'   => $log->student->firstname ?? 'Unknown',
                'course'      => $log->student->course ?? 'Unknown',
                'section'     => $log->section ?? '—',
                'gate'        => $log->gate ?? '—',
                'status'      => ($log->status === 'IN' && $log->isLateArrival()) ? 'LATE' : strtoupper($log->status),
                'scanned_at'  => $log->scanned_at?->format('Y-m-d h:i A') ?? '—',
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Last Name',
            'First Name',
            'Course',
            'Section',
            'Gate',
            'Status',
            'Scanned At',
        ];
    }
}
