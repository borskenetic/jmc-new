<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AttendanceLog;
use App\Models\GradeSection;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use App\Services\PatronAttendanceReportService;
use App\Support\PatronOptions;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\AttendanceLogsExport;

class AttendanceLogController extends Controller
{
    public function index(Request $request)
    {
        $this->applyDefaultDateRange($request);

        $baseQuery = $this->filteredLogs($request);

        $logs = (clone $baseQuery)
            ->paginate(25)
            ->withQueryString();

        $summary = $this->summaryForQuery(clone $baseQuery);

        $yearOptions = PatronOptions::allYearOptions();

        $homeroomSections = collect();
        if (Schema::hasTable('grade_sections')) {
            $homeroomSections = $homeroomSections->merge(
                GradeSection::query()->orderBy('section')->pluck('section')
            );
        }
        $homeroomSections = $homeroomSections
            ->merge(
                Student::query()
                    ->whereNotNull('section')
                    ->where('section', '!=', '')
                    ->distinct()
                    ->orderBy('section')
                    ->pluck('section')
            )
            ->unique()
            ->sort()
            ->values();

        // Prefer configured terminals only — scanning all log gates is expensive on large tables.
        $gateOptions = collect(Setting::gateTerminals())->filter()->unique()->sort()->values();

        return view('attendance_logs.index', compact(
            'logs',
            'summary',
            'yearOptions',
            'homeroomSections',
            'gateOptions',
        ));
    }

    /** Default the list to Today unless All time (period=all) is requested without dates. */
    private function applyDefaultDateRange(Request $request): void
    {
        if ($this->wantsAllTime($request)) {
            return;
        }

        if (! $request->filled('from') && ! $request->filled('to')) {
            $today = now(config('app.timezone', 'Asia/Manila'))->toDateString();
            $request->merge([
                'from' => $today,
                'to' => $today,
            ]);
        }
    }

    private function wantsAllTime(Request $request): bool
    {
        return $request->query('period') === 'all'
            && ! $request->filled('from')
            && ! $request->filled('to');
    }

    /** @return array{total: int, in: int, late: int, out: int, today: int} */
    private function summaryForQuery($query): array
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $todayStart = now($tz)->startOfDay()->toDateTimeString();
        $todayEnd = now($tz)->endOfDay()->toDateTimeString();

        $row = (clone $query)
            ->toBase()
            ->reorder()
            ->selectRaw(
                "COUNT(*) as total,
                 SUM(CASE WHEN UPPER(status) = 'IN' THEN 1 ELSE 0 END) as cin,
                 SUM(CASE WHEN UPPER(status) = 'IN' AND is_late = 1 THEN 1 ELSE 0 END) as late,
                 SUM(CASE WHEN UPPER(status) = 'OUT' THEN 1 ELSE 0 END) as cout,
                 SUM(CASE WHEN scanned_at >= ? AND scanned_at <= ? THEN 1 ELSE 0 END) as today",
                [$todayStart, $todayEnd]
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'in' => (int) ($row->cin ?? 0),
            'late' => (int) ($row->late ?? 0),
            'out' => (int) ($row->cout ?? 0),
            'today' => (int) ($row->today ?? 0),
        ];
    }

    private function filteredLogs(Request $request)
    {
        $this->applyDefaultDateRange($request);

        $status = strtoupper((string) $request->status);
        $allTime = $this->wantsAllTime($request);
        $tz = config('app.timezone', 'Asia/Manila');

        $from = $allTime ? null : $request->input('from');
        $to = $allTime ? null : $request->input('to');

        return AttendanceLog::query()
            ->with(['student:id,firstname,lastname,student_id,year,section,course'])

            ->when($from, function ($q) use ($from, $tz) {
                $q->where(
                    'scanned_at',
                    '>=',
                    Carbon::parse($from, $tz)->startOfDay()
                );
            })

            ->when($to, function ($q) use ($to, $tz) {
                $q->where(
                    'scanned_at',
                    '<=',
                    Carbon::parse($to, $tz)->endOfDay()
                );
            })

            ->when($request->year ?: $request->year_level,
                fn ($q) => $q->whereHas('student',
                    fn ($q2) => $q2->where('year', $request->year ?: $request->year_level)
                ))

            ->when($request->homeroom_section,
                fn ($q) => $q->whereHas('student',
                    fn ($q2) => $q2->where('section', $request->homeroom_section)
                ))

            ->when($status === 'LATE',
                fn ($q) => $q->where('status', 'IN')->where('is_late', true)
            )

            ->when($status === 'IN',
                fn ($q) => $q->where('status', 'IN')->where(function ($q2) {
                    $q2->where('is_late', false)->orWhereNull('is_late');
                })
            )

            ->when($status === 'OUT',
                fn ($q) => $q->where('status', 'OUT')
            )

            ->when($request->gate && Schema::hasColumn('attendance_logs', 'gate'),
                fn ($q) => $q->where('gate', $request->gate)
            )

            ->when($request->search, function ($q) use ($request) {
                $search = $request->search;

                $q->where(function ($query) use ($search) {
                    $query->whereHas('student', function ($q2) use ($search) {
                        $q2->where('firstname', 'like', "%{$search}%")
                            ->orWhere('lastname', 'like', "%{$search}%")
                            ->orWhere('student_id', 'like', "%{$search}%");
                    });
                });
            })

            ->orderByDesc('scanned_at');
    }

    public function create()
    {
        $students = Student::all();
        return view('attendance_logs.create', compact('students'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
            'status' => 'required|in:in,out,IN,OUT',
            'scanned_at' => 'required|date',
        ]);

        $status = strtoupper((string) $request->input('status'));
        $scannedAt = Carbon::parse($request->input('scanned_at'));
        $isLate = $status === 'IN' && app(\App\Services\StudentAttendanceSchedule::class)->isLate(
            $scannedAt,
            Student::find($request->input('student_id'))
        );

        AttendanceLog::create([
            'student_id' => $request->input('student_id'),
            'status' => $status,
            'is_late' => $isLate,
            'scanned_at' => $scannedAt,
        ]);

        return redirect()->route('attendance_logs.index')
            ->with('success', 'Attendance logged!');
    }

    public function exportPdf(Request $request)
    {
        $logs = $this->filteredLogs($request)->get();

        $pdf = Pdf::loadView('attendance_logs.pdf', compact('logs'));
        return $pdf->download('attendance_logs.pdf');
    }

    public function exportExcel(Request $request)
    {
        $logs = $this->filteredLogs($request)->get();

        return Excel::download(
            new AttendanceLogsExport($logs),
            'attendance_logs.xlsx'
        );
    }

    public function reportsHub()
    {
        return view('attendance_logs.reports_hub');
    }

    public function reportsDashboard(Request $request, PatronAttendanceReportService $patronReports)
    {
        $programNameByCode = collect();
        $only = $request->query('only');
        $from = $request->query('from');
        $to = $request->query('to');

        return view('attendance_logs.reports_dashboard', array_merge(
            compact('programNameByCode', 'only', 'from', 'to'),
            $patronReports->build($from, $to)
        ));
    }

    public function reportsExportCsv(Request $request, PatronAttendanceReportService $patronReports)
    {
        return $patronReports->streamCsvResponse(
            $request->query('from'),
            $request->query('to')
        );
    }
}
