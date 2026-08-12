@extends('layouts.sec')

@section('title', 'Students')

@push('styles')
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/layout/data-pages.css') }}">
@endpush

@section('content')
@php
    use App\Enums\EducationalLevel;
    use App\Support\ProfilePicture;

    $rfidMissing = max(0, ($totalStudents ?? 0) - ($rfidAssigned ?? 0));
    $hasFilters = request()->hasAny(['search', 'educational_level', 'program_id', 'year']);
@endphp

<div class="data-page students-page mt-2">
    <div class="students-page__header">
        <div>
            <h1 class="students-page__title">Students</h1>
            <p class="students-page__subtitle">Manage records and RFID gate tags.</p>
        </div>
        <div class="students-stats">
            <div class="students-stat">
                <span class="students-stat__label">Total</span>
                <span class="students-stat__value">{{ number_format($totalStudents ?? 0) }}</span>
            </div>
            <div class="students-stat students-stat--ok">
                <span class="students-stat__label">RFID assigned</span>
                <span class="students-stat__value">{{ number_format($rfidAssigned ?? 0) }}</span>
            </div>
            <div class="students-stat students-stat--warn">
                <span class="students-stat__label">No RFID</span>
                <span class="students-stat__value">{{ number_format($rfidMissing) }}</span>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
    @if(session('student_import_errors') && count(session('student_import_errors')) > 0)
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <strong>Import notes:</strong>
            <ul class="mb-0 mt-1 small">
                @foreach(array_slice(session('student_import_errors'), 0, 5) as $note)
                    <li>{{ $note }}</li>
                @endforeach
                @if(count(session('student_import_errors')) > 5)
                    <li>…and {{ count(session('student_import_errors')) - 5 }} more</li>
                @endif
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
    @if(session('rfid_import_errors') && count(session('rfid_import_errors')) > 0)
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <strong>Import notes:</strong>
            <ul class="mb-0 mt-1 small">
                @foreach(array_slice(session('rfid_import_errors'), 0, 5) as $note)
                    <li>{{ $note }}</li>
                @endforeach
                @if(count(session('rfid_import_errors')) > 5)
                    <li>…and {{ count(session('rfid_import_errors')) - 5 }} more</li>
                @endif
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <nav class="students-segment" aria-label="Patron type">
        <a href="{{ route('students.index') }}" class="active">Students</a>
        <a href="{{ route('employees.index') }}">Employees</a>
    </nav>

    <form action="{{ route('students.index') }}" method="GET" class="students-filters">
        <div class="row g-2 align-items-end">
            <div class="col-lg-4">
                <label class="form-label small text-muted mb-1" for="studentSearch">Search</label>
                <div class="students-filters__search">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
                    </svg>
                    <input type="text" name="search" id="studentSearch" class="form-control"
                           placeholder="Name, student ID, RFID, course…" value="{{ request('search') }}">
                </div>
            </div>
            <div class="col-md-6 col-lg-2">
                <label class="form-label small text-muted mb-1" for="levelFilter">Level</label>
                <select name="educational_level" id="levelFilter" class="form-select">
                    <option value="">All levels</option>
                    @foreach (EducationalLevel::options() as $value => $label)
                        <option value="{{ $value }}" {{ request('educational_level') == $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 col-lg-2">
                <label class="form-label small text-muted mb-1" for="programFilter">Course</label>
                <select name="program_id" id="programFilter" class="form-select">
                    <option value="">All courses</option>
                    @foreach ($programs as $program)
                        <option value="{{ $program->program_code }}"
                            {{ request('program_id') == $program->program_code ? 'selected' : '' }}>
                            {{ $program->program_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 col-lg-2">
                <label class="form-label small text-muted mb-1" for="yearFilter">Year</label>
                <select name="year" id="yearFilter" class="form-select">
                    <option value="">All years</option>
                    @foreach(\App\Support\PatronOptions::allYearOptions() as $y)
                        <option value="{{ $y }}" {{ request('year') == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 col-lg-2">
                <div class="students-filters__actions">
                    <button type="submit" class="btn btn-primary flex-grow-1">Apply</button>
                    @if($hasFilters)
                        <a href="{{ route('students.index') }}" class="btn btn-outline-secondary">Clear</a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    @include('partials.patron-data-toolbar', [
        'registerRoute' => auth()->user()?->can('isAdmin') ? route('students.create') : null,
        'registerLabel' => '+ Register student',
        'pendingUrl' => route('pending.index', ['tab' => 'students']),
        'importTemplateRoute' => 'students.import.template',
        'importRoute' => 'students.import',
        'rfidImportTemplateRoute' => 'students.rfid.import.template',
        'rfidImportRoute' => 'students.rfid.import',
        'exportRoute' => route('students.export', request()->query()),
    ])

    <div class="students-table-card">
        <div class="table-responsive">
            <table class="table students-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col" class="text-center" style="width:56px"></th>
                        <th scope="col">Student</th>
                        <th scope="col">Student ID</th>
                        <th scope="col">RFID</th>
                        <th scope="col">Level / Year</th>
                        <th scope="col">Course</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($students as $student)
                        <tr>
                            <td class="text-center">
                                @if($url = ProfilePicture::url($student->getRawOriginal('profile_picture')))
                                    <img src="{{ $url }}" alt="" class="students-avatar">
                                @else
                                    <span class="students-avatar--empty" aria-hidden="true">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="students-name">
                                    {{ $student->lastname }}, {{ $student->firstname }}
                                    @if($student->midname)
                                        <small>{{ $student->midname }}</small>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="students-id">{{ $student->student_id ?? '—' }}</span>
                            </td>
                            <td>
                                @if($student->rfid)
                                    <span class="students-rfid" title="{{ $student->rfid }}">{{ $student->rfid }}</span>
                                @else
                                    <span class="students-rfid students-rfid--missing">Not set</span>
                                @endif
                            </td>
                            <td>
                                <span class="students-level">{{ $student->educational_level?->label() ?? '—' }}</span>
                                @if($student->year)
                                    <br><small class="text-muted">{{ $student->year }}</small>
                                @endif
                            </td>
                            <td>{{ $student->course ?? '—' }}</td>
                            <td>
                                <div class="students-actions">
                                    @can('isAdmin')
                                        <a href="{{ route('students.edit', $student->id) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                        <form action="{{ route('students.destroy', $student->id) }}" method="POST"
                                              onsubmit="return confirm('Delete this student?');" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                        </form>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="students-empty">
                                    <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                    </svg>
                                    <h6>No students found</h6>
                                    <p class="small mb-0">Try adjusting filters or register a new student.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-center mt-3">
        {{ $students->withQueryString()->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection
