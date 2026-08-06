@extends('layouts.sec')

@section('content')

<div class="container mt-4">

    <div class="d-flex justify-content-between align-items-center mb-3">

        <h3>Gate Terminal SMS Message</h3>

        <a href="/sms-blast" class="btn btn-secondary">
            Back to SMS Blast
        </a>

    </div>

    @if(session('success'))
    <div class="alert alert-success">
        {{ session('success') }}
    </div>
    @endif

    <form method="POST">
        @csrf

        <div class="mb-3">

            <label>Message Template</label>

            <textarea name="message" class="form-control" rows="5">{{ $message }}</textarea>

            <small class="text-muted">

                Sent to the student’s emergency contact number on each campus scan.
                <br>
                Available tags:
                <br><b>{name}</b> – student name
                <br><b>{status}</b> – IN or OUT
                <br><b>{time}</b> – scan time

            </small>

        </div>

        <button class="btn btn-primary">
            Save Template
        </button>

    </form>

</div>

@endsection