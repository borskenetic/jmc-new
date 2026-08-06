@extends('layouts.app')

@section('title', 'SMS Blast')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
@endpush

@section('content')
<div class="data-page container-fluid px-0 mt-2" style="max-width: 900px;">
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
                <div class="col-md-3">
                    <label for="recipientFilter" class="form-label">Send to</label>
                    <select name="recipient" id="recipientFilter" class="form-control" required>
                        <option value="emergency_contact" @selected(old('recipient', 'emergency_contact') === 'emergency_contact')>
                            Emergency contact (parent/guardian)
                        </option>
                        <option value="student" @selected(old('recipient') === 'student')>
                            Student mobile number
                        </option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="yearFilter" class="form-label">Filter by Year / Grade</label>
                    <select name="year" id="yearFilter" class="form-control">
                        <option value="">All years / grades</option>
                        @foreach($yearOptions as $year)
                            <option value="{{ $year }}" @selected(old('year') === $year)>{{ $year }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="sectionFilter" class="form-label">Filter by Section</label>
                    <select name="section" id="sectionFilter" class="form-control">
                        <option value="">All sections</option>
                        @foreach($sections as $section)
                            <option value="{{ $section }}" @selected(old('section') === $section)>{{ $section }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="courseFilter" class="form-label">Filter by Course / Strand</label>
                    <select name="course" id="courseFilter" class="form-control">
                        <option value="">All courses</option>
                        @foreach($courses as $course)
                            <option value="{{ $course }}" @selected(old('course') === $course)>{{ $course }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="alert alert-info mb-3">
                Recipients: <b id="recipientCount">Loading...</b>
                <span id="recipientLabel">emergency contacts</span>
            </div>

            <div class="mb-3">
                <label for="smsMessage" class="form-label">Message</label>
                <textarea
                    id="smsMessage"
                    name="message"
                    class="form-control"
                    rows="5"
                    placeholder="Example: Hello {name}, please visit the campus office today."
                    required
                >{{ old('message') }}</textarea>
                <small class="text-muted">
                    Available variables:<br>
                    <b>{name}</b> = Student full name
                </small>
            </div>

            <button type="submit" class="btn btn-primary">Send SMS</button>
            <a href="{{ route('activity_logs.index', ['tab' => 'sms']) }}" class="btn btn-outline-secondary ms-1">View SMS logs</a>
            <a href="{{ route('sms.scanMessage') }}" class="btn btn-outline-secondary ms-1">Gate terminal message</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
const sectionsByGrade = @json($sectionsByGrade ?? new \stdClass());
const allSections = @json($sections);

function rebuildSectionOptions() {
    const year = document.getElementById('yearFilter').value;
    const sectionSelect = document.getElementById('sectionFilter');
    const current = sectionSelect.value;

    let list = allSections;
    if (year && sectionsByGrade[year] && sectionsByGrade[year].length) {
        list = sectionsByGrade[year];
    }

    sectionSelect.innerHTML = '<option value="">All sections</option>';
    list.forEach(function (sec) {
        const opt = document.createElement('option');
        opt.value = sec;
        opt.textContent = sec;
        if (sec === current) {
            opt.selected = true;
        }
        sectionSelect.appendChild(opt);
    });
}

function updateRecipientCount() {
    const year = document.getElementById('yearFilter').value;
    const course = document.getElementById('courseFilter').value;
    const section = document.getElementById('sectionFilter').value;
    const recipient = document.getElementById('recipientFilter').value;
    const labels = {
        emergency_contact: 'emergency contacts',
        student: 'students with mobile numbers',
    };

    const params = new URLSearchParams({ year, course, section, recipient });

    fetch("{{ route('sms.count') }}?" + params.toString())
        .then(res => res.json())
        .then(data => {
            document.getElementById('recipientCount').innerText = data.count;
            document.getElementById('recipientLabel').innerText = labels[recipient] || '';
        })
        .catch(() => {
            document.getElementById('recipientCount').innerText = '?';
        });
}

document.getElementById('yearFilter').addEventListener('change', function () {
    rebuildSectionOptions();
    updateRecipientCount();
});
document.getElementById('courseFilter').addEventListener('change', updateRecipientCount);
document.getElementById('sectionFilter').addEventListener('change', updateRecipientCount);
document.getElementById('recipientFilter').addEventListener('change', updateRecipientCount);

rebuildSectionOptions();
updateRecipientCount();
</script>
@endpush
