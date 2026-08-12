@extends('layouts.sec')

@section('content')
@php
    $permanent = $permanent ?? ['in_time' => '07:30', 'out_time' => '14:00', 'grace_minutes' => 10];
@endphp
<div class="container py-4" style="max-width: 720px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="mb-0">IN / OUT schedule</h3>
        <a href="{{ route('attendance_logs.index') }}" class="btn btn-outline-secondary btn-sm">Attendance logs</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('attendance.schedule.settings.update') }}" class="mb-4">
        @csrf

        <div class="card mb-4">
            <div class="card-header fw-semibold">Temporary time change</div>
            <div class="card-body">
                <p class="text-muted">
                    While active, these times override the permanent schedule below for all students.
                    After the end date, the system automatically uses the permanent times again
                    (permanent fields are never overwritten by a temporary change).
                </p>

                <div class="form-check mb-3">
                    <input type="hidden" name="temporary[enabled]" value="0">
                    <input class="form-check-input" type="checkbox" id="tempEnabled"
                           name="temporary[enabled]" value="1"
                           {{ old('temporary.enabled', $temporary['enabled'] ?? false) ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="tempEnabled">Enable temporary time change</label>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="temp_in">Temp login</label>
                        <input type="time" class="form-control" id="temp_in" name="temporary[in_time]"
                               value="{{ old('temporary.in_time', $temporary['in_time'] ?? '') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="temp_out">Temp logout</label>
                        <input type="time" class="form-control" id="temp_out" name="temporary[out_time]"
                               value="{{ old('temporary.out_time', $temporary['out_time'] ?? '') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="temp_start">Starts on</label>
                        <input type="date" class="form-control" id="temp_start" name="temporary[starts_on]"
                               value="{{ old('temporary.starts_on', $temporary['starts_on'] ?? '') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="temp_end">Ends on</label>
                        <input type="date" class="form-control" id="temp_end" name="temporary[ends_on]"
                               value="{{ old('temporary.ends_on', $temporary['ends_on'] ?? '') }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Permanent schedule</div>
            <div class="card-body">
                <p class="text-muted small">
                    Applies to all students. Late = first IN after IN time + grace.
                    OUT scans are only accepted from <strong>{{ $outAllowedFromLabel }}</strong> onward
                    (one IN and one OUT per student per day).
                </p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="in_time">IN time</label>
                        <input type="time" class="form-control" id="in_time" name="in_time"
                               value="{{ old('in_time', $permanent['in_time']) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="out_time">OUT time</label>
                        <input type="time" class="form-control" id="out_time" name="out_time"
                               value="{{ old('out_time', $permanent['out_time']) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="grace_minutes">Grace (minutes)</label>
                        <input type="number" class="form-control" id="grace_minutes" name="grace_minutes"
                               value="{{ old('grace_minutes', $permanent['grace_minutes']) }}"
                               min="0" max="180" required>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save schedule</button>
    </form>

    <div class="card">
        <div class="card-header fw-semibold">Update existing records</div>
        <div class="card-body">
            <p class="text-muted">
                Recompute the late flag on existing <strong>IN</strong> scans using the schedule above
                (including temporary overrides for dates in range). Does not change IN/OUT status.
            </p>
            <form method="POST" action="{{ route('attendance.schedule.backfill') }}" class="row g-3 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label class="form-label" for="bf_from">From (optional)</label>
                    <input type="date" class="form-control" id="bf_from" name="from" value="{{ old('from') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="bf_to">To (optional)</label>
                    <input type="date" class="form-control" id="bf_to" name="to" value="{{ old('to') }}">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-outline-warning"
                            onclick="return confirm('Recompute late flags on matching IN records?')">
                        Mark late on existing records
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
