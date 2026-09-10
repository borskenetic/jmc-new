@extends('layouts.app')

@section('title', 'Gate SMS settings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
@endpush

@section('content')
@php
    $scanSmsEnabled = $scanSmsEnabled ?? ['arrival' => true, 'departure' => true];
    $enabledOld = function (string $event) use ($scanSmsEnabled): string {
        $default = ($scanSmsEnabled[$event] ?? true) ? '1' : '0';

        return (string) old($event.'_enabled', $default);
    };
@endphp
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

    <p class="text-muted mb-3">
        Arrival &amp; departure messages for gate scans.
        Use <strong>Auto SMS on</strong> on each card to enable or disable that automatic message.
    </p>

    <form method="POST" action="{{ route('sms.scanMessage.update') }}">
        @csrf

        <div class="card mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>First scan of the day (arrival)</span>
                <input type="hidden" name="arrival_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="arrivalEnabled"
                           name="arrival_enabled" value="1"
                           @checked($enabledOld('arrival') === '1')>
                    <label class="form-check-label" for="arrivalEnabled">Auto SMS on</label>
                </div>
            </div>
            <div class="card-body">
                <textarea name="arrival" class="form-control" rows="4" required>{{ old('arrival', $arrival) }}</textarea>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Departure (once per day after logout time)</span>
                <input type="hidden" name="departure_enabled" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="departureEnabled"
                           name="departure_enabled" value="1"
                           @checked($enabledOld('departure') === '1')>
                    <label class="form-check-label" for="departureEnabled">Auto SMS on</label>
                </div>
            </div>
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
