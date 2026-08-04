@extends('layouts.app')

@section('title', 'SMS Blast')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
@endpush

@section('content')
<div class="data-page container-fluid px-0 mt-2" style="max-width: 720px;">
    <h3 class="mb-3">SMS Blast</h3>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
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

    <form method="POST" action="{{ route('sms.send') }}" class="card border-0 shadow-sm">
        @csrf
        <div class="card-body">
            <div class="row mb-3 g-3">
                <div class="col-md-6">
                    <label for="yearFilter" class="form-label">Filter by Year</label>
                    <select name="year" id="yearFilter" class="form-control">
                        <option value="">All Years</option>
                        <option value="1" @selected(old('year') === '1')>1st Year</option>
                        <option value="2" @selected(old('year') === '2')>2nd Year</option>
                        <option value="3" @selected(old('year') === '3')>3rd Year</option>
                        <option value="4" @selected(old('year') === '4')>4th Year</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="courseFilter" class="form-label">Filter by Course</label>
                    <select name="course" id="courseFilter" class="form-control">
                        <option value="">All Courses</option>
                        @foreach($courses as $course)
                            <option value="{{ $course }}" @selected(old('course') === $course)>{{ $course }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="alert alert-info mb-3">
                Recipients: <b id="recipientCount">Loading...</b> students
            </div>

            <div class="mb-3">
                <label for="smsMessage" class="form-label">Message</label>
                <textarea
                    id="smsMessage"
                    name="message"
                    class="form-control"
                    rows="5"
                    placeholder="Example: Hello {name}, please visit the library today."
                    required
                >{{ old('message') }}</textarea>
                <small class="text-muted">
                    Available variables:<br>
                    <b>{name}</b> = Student full name
                </small>
            </div>

            <button type="submit" class="btn btn-primary">Send SMS</button>
            <a href="{{ route('activity_logs.index', ['tab' => 'sms']) }}" class="btn btn-outline-secondary ms-1">View SMS logs</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
function updateRecipientCount() {
    const year = document.getElementById('yearFilter').value;
    const course = document.getElementById('courseFilter').value;
    const params = new URLSearchParams({ year, course });

    fetch("{{ route('sms.count') }}?" + params.toString())
        .then(res => res.json())
        .then(data => {
            document.getElementById('recipientCount').innerText = data.count;
        })
        .catch(() => {
            document.getElementById('recipientCount').innerText = '?';
        });
}

document.getElementById('yearFilter').addEventListener('change', updateRecipientCount);
document.getElementById('courseFilter').addEventListener('change', updateRecipientCount);
updateRecipientCount();
</script>
@endpush
