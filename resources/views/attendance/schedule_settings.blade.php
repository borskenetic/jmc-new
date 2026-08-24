@extends('layouts.sec')

@section('content')
@php
    $permanentK10 = $permanentK10 ?? ['in_time' => '07:30', 'out_time' => '14:00', 'grace_minutes' => 10];
    $permanentShs = $permanentShs ?? ['in_time' => '14:30', 'out_time' => '14:00', 'grace_minutes' => 10];
    $temporaryK10 = $temporaryK10 ?? ['enabled' => false, 'in_time' => null, 'out_time' => null, 'starts_on' => null, 'ends_on' => null];
    $temporaryShs = $temporaryShs ?? ['enabled' => false, 'in_time' => null, 'out_time' => null, 'starts_on' => null, 'ends_on' => null];
    $tempEnabled = old('temporary.enabled', $temporaryK10['enabled'] ?? false);
@endphp
<div class="container py-4" style="max-width: 820px;">
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
                    While active, these times override the permanent schedules below for the matching student group.
                    After the end date, the system automatically uses the permanent times again
                    (permanent fields are never overwritten by a temporary change).
                </p>

                <div class="form-check mb-3">
                    <input type="hidden" name="temporary[enabled]" value="0">
                    <input class="form-check-input" type="checkbox" id="tempEnabled"
                           name="temporary[enabled]" value="1"
                           {{ $tempEnabled ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="tempEnabled">Enable temporary time change</label>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="temp_start">Starts on</label>
                        <input type="date" class="form-control" id="temp_start" name="temporary[starts_on]"
                               value="{{ old('temporary.starts_on', $temporaryK10['starts_on'] ?? '') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="temp_end">Ends on</label>
                        <input type="date" class="form-control" id="temp_end" name="temporary[ends_on]"
                               value="{{ old('temporary.ends_on', $temporaryK10['ends_on'] ?? '') }}">
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <h6 class="fw-semibold mb-2">Kinder – Grade 10</h6>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label" for="temp_k10_in">Temp login</label>
                                <input type="time" class="form-control" id="temp_k10_in" name="temporary[k10][in_time]"
                                       value="{{ old('temporary.k10.in_time', $temporaryK10['in_time'] ?? '') }}">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="temp_k10_out">Temp logout</label>
                                <input type="time" class="form-control" id="temp_k10_out" name="temporary[k10][out_time]"
                                       value="{{ old('temporary.k10.out_time', $temporaryK10['out_time'] ?? '') }}">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-semibold mb-2">SHS (Grade 11–12)</h6>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label" for="temp_shs_in">Temp login</label>
                                <input type="time" class="form-control" id="temp_shs_in" name="temporary[shs][in_time]"
                                       value="{{ old('temporary.shs.in_time', $temporaryShs['in_time'] ?? '') }}">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="temp_shs_out">Temp logout</label>
                                <input type="time" class="form-control" id="temp_shs_out" name="temporary[shs][out_time]"
                                       value="{{ old('temporary.shs.out_time', $temporaryShs['out_time'] ?? '') }}">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Permanent schedule — Kinder to Grade 10</div>
            <div class="card-body">
                <p class="text-muted small">
                    Applies to Kinder and Grades 1–10. Late = first IN after IN time + grace.
                    OUT scans are only accepted from <strong>{{ $outAllowedFromLabel }}</strong> onward
                    (one IN and one OUT per student per day).
                </p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="k10_in_time">IN time</label>
                        <input type="time" class="form-control" id="k10_in_time" name="groups[k10][in_time]"
                               value="{{ old('groups.k10.in_time', $permanentK10['in_time']) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="k10_out_time">OUT time</label>
                        <input type="time" class="form-control" id="k10_out_time" name="groups[k10][out_time]"
                               value="{{ old('groups.k10.out_time', $permanentK10['out_time']) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="k10_grace_minutes">Grace (minutes)</label>
                        <input type="number" class="form-control" id="k10_grace_minutes" name="groups[k10][grace_minutes]"
                               value="{{ old('groups.k10.grace_minutes', $permanentK10['grace_minutes']) }}"
                               min="0" max="180" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Permanent schedule — SHS (Grade 11–12)</div>
            <div class="card-body">
                <p class="text-muted small">
                    Applies to Senior High School students. Late = first IN after IN time + grace.
                    Default IN time is 2:30 PM.
                </p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="shs_in_time">IN time</label>
                        <input type="time" class="form-control" id="shs_in_time" name="groups[shs][in_time]"
                               value="{{ old('groups.shs.in_time', $permanentShs['in_time']) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="shs_out_time">OUT time</label>
                        <input type="time" class="form-control" id="shs_out_time" name="groups[shs][out_time]"
                               value="{{ old('groups.shs.out_time', $permanentShs['out_time']) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="shs_grace_minutes">Grace (minutes)</label>
                        <input type="number" class="form-control" id="shs_grace_minutes" name="groups[shs][grace_minutes]"
                               value="{{ old('groups.shs.grace_minutes', $permanentShs['grace_minutes']) }}"
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
                Recompute the late flag on existing <strong>IN</strong> scans using the schedules above
                (including temporary overrides for dates in range, by student group). Does not change IN/OUT status.
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
