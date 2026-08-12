@extends('layouts.sec')

@section('content')
<div class="container py-4" style="max-width: 720px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="mb-0">Student attendance schedule</h3>
        <a href="{{ route('attendance.scan') }}" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
            Open gate terminal
        </a>
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

    <div class="card mb-4">
        <div class="card-body">
            <p class="text-muted mb-0">
                Students who scan <strong>IN</strong> after the late cutoff are marked <strong>LATE</strong>.
                Cutoff = IN time + grace period. SF2 tardy marks use the same rule.
            </p>
        </div>
    </div>

    <form method="POST" action="{{ route('attendance.schedule.settings.update') }}">
        @csrf

        <div class="card mb-4">
            <div class="card-header fw-semibold">Times</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="in_time">IN time</label>
                        <input type="time" class="form-control" id="in_time" name="in_time"
                               value="{{ old('in_time', $inTime) }}" required>
                        <div class="form-text">Default class start (e.g. 7:30 AM).</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="grace_minutes">Grace period (minutes)</label>
                        <input type="number" class="form-control" id="grace_minutes" name="grace_minutes"
                               value="{{ old('grace_minutes', $graceMinutes) }}" min="0" max="180" required>
                        <div class="form-text">IN after this window is late.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="out_time">OUT time</label>
                        <input type="time" class="form-control" id="out_time" name="out_time"
                               value="{{ old('out_time', $outTime) }}" required>
                        <div class="form-text">Expected dismissal time.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold">Current rule</div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>IN: <strong>{{ $inTimeLabel }}</strong></li>
                    <li>Grace: <strong>{{ $graceMinutes }}</strong> minute{{ $graceMinutes === 1 ? '' : 's' }}</li>
                    <li>Late after: <strong>{{ $lateCutoffLabel }}</strong></li>
                    <li>OUT: <strong>{{ $outTimeLabel }}</strong></li>
                </ul>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save schedule</button>
    </form>
</div>
@endsection
