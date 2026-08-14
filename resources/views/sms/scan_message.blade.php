@extends('layouts.app')

@section('title', 'Gate SMS settings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
@endpush

@section('content')
<div class="data-page container-fluid px-0 mt-2" style="max-width: 900px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="mb-0">Gate SMS settings</h3>
        <a href="{{ route('sms.page') }}" class="btn btn-outline-secondary">Back to SMS Blast</a>
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

    <p class="text-muted mb-3">SHS / College (arrival &amp; departure)</p>

    <form method="POST" action="{{ route('sms.scanMessage.update') }}">
        @csrf

        <div class="card mb-3">
            <div class="card-header bg-light">First scan of the day (arrival)</div>
            <div class="card-body">
                <textarea name="arrival" class="form-control" rows="4" required>{{ old('arrival', $arrival) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-light">Departure (once per day after logout time)</div>
            <div class="card-body">
                <textarea name="departure" class="form-control" rows="4" required>{{ old('departure', $departure) }}</textarea>
            </div>
        </div>

        <small class="text-muted d-block mb-3">
            Sent to the student’s emergency contact. Available tags:
            <b>{contact}</b> – emergency contact name,
            <b>{name}</b> – student name,
            <b>{status}</b> – IN, LATE, or OUT,
            <b>{time}</b> – scan time.
        </small>

        <button class="btn btn-primary" type="submit">Save templates</button>
    </form>
</div>
@endsection
