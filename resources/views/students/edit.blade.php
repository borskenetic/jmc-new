@extends('layouts.sec')

@section('title', 'Edit Student')

@push('styles')
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/layout/data-pages.css') }}">
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
@endpush

@section('content')
@php
    use App\Support\ProfilePicture;

    $birthValue = old('birth_date', $student->birth_date);
    if ($birthValue) {
        $birthValue = substr((string) $birthValue, 0, 10);
    }
    $levelValue = old('educational_level', $student->educational_level?->value ?? $student->educational_level ?? 'college');
    $profileUrl = ProfilePicture::url($student->getRawOriginal('profile_picture'));
@endphp

<div class="data-page student-edit-page mt-2">
    <header class="student-edit-header">
        <div>
            <a href="{{ route('students.index') }}" class="student-edit-back">&larr; Back to students</a>
            <h1 class="student-edit-title">{{ $student->lastname }}, {{ $student->firstname }}</h1>
            <p class="student-edit-subtitle">
                Student ID <strong>{{ $student->student_id ?? '—' }}</strong>
                @if($student->year || $student->course)
                    &middot; {{ trim(($student->year ?? '').' '.($student->course ?? '')) }}
                @endif
            </p>
        </div>
    </header>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <strong>Please fix the following:</strong>
            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="studentForm" method="POST" action="{{ route('students.update', $student->id) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="row g-4 student-edit-layout">
            <aside class="col-lg-4">
                <div class="student-edit-panel student-edit-identity">
                    <div class="student-edit-panel__head">Identity</div>
                    <div class="student-edit-panel__body text-center">
                        <div class="student-edit-avatar-wrap">
                            @if($profileUrl)
                                <img src="{{ $profileUrl }}" alt="" class="student-edit-avatar" id="profilePreview">
                            @else
                                <div class="student-edit-avatar student-edit-avatar--empty" id="profilePreviewPlaceholder">
                                    {{ strtoupper(substr($student->firstname, 0, 1)) }}
                                </div>
                                <img src="" alt="" class="student-edit-avatar d-none" id="profilePreview">
                            @endif
                        </div>

                        <div class="student-edit-field text-start">
                            <label for="qrcode" class="form-label">QR code</label>
                            <input type="text" id="qrcode" class="form-control form-control-sm bg-light font-monospace" value="{{ $student->qrcode ?? '—' }}" readonly>
                            <p class="student-edit-hint">Assigned by the system — used if RFID is empty.</p>
                        </div>

                        <div class="student-edit-field text-start">
                            <label for="rfid" class="form-label">RFID tag</label>
                            <input type="text" name="rfid" id="rfid" class="form-control form-control-sm @error('rfid') is-invalid @enderror"
                                   value="{{ old('rfid', $student->rfid) }}" placeholder="Scan or type tag"
                                   autocomplete="off">
                            @error('rfid')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <p class="student-edit-hint">Primary identifier at the gate scanner.</p>
                        </div>
                    </div>
                </div>

                @if(config('face.enabled'))
                    <div class="student-edit-panel student-edit-face">
                        <div class="student-edit-panel__head">Face recognition</div>
                        <div class="student-edit-panel__body">
                            @include('students.partials.face-enroll', ['student' => $student])
                        </div>
                    </div>
                @endif
            </aside>

            <div class="col-lg-8">
                <div class="student-edit-panel">
                    <div class="student-edit-panel__head">Personal details</div>
                    <div class="student-edit-panel__body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="firstname" class="form-label">First name <span class="text-danger">*</span></label>
                                <input type="text" name="firstname" id="firstname" class="form-control @error('firstname') is-invalid @enderror"
                                       value="{{ old('firstname', $student->firstname) }}" required>
                                @error('firstname')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="lastname" class="form-label">Last name <span class="text-danger">*</span></label>
                                <input type="text" name="lastname" id="lastname" class="form-control @error('lastname') is-invalid @enderror"
                                       value="{{ old('lastname', $student->lastname) }}" required>
                                @error('lastname')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="midname" class="form-label">Middle name</label>
                                <input type="text" name="midname" id="midname" class="form-control"
                                       value="{{ old('midname', $student->midname) }}" maxlength="255">
                            </div>
                            <div class="col-md-6">
                                <label for="student_id" class="form-label">Student ID <span class="text-danger">*</span></label>
                                <input type="text" name="student_id" id="student_id" class="form-control @error('student_id') is-invalid @enderror"
                                       value="{{ old('student_id', $student->student_id) }}" required>
                                @error('student_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="lrn" class="form-label">LRN</label>
                                <input type="text" name="lrn" id="lrn" class="form-control @error('lrn') is-invalid @enderror"
                                       value="{{ old('lrn', $student->lrn) }}" placeholder="Learner reference number">
                                @error('lrn')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="birth_date" class="form-label">Birthday</label>
                                <input type="date" name="birth_date" id="birth_date" class="form-control @error('birth_date') is-invalid @enderror"
                                       value="{{ $birthValue }}">
                                @error('birth_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="mobile_number" class="form-label">Mobile number</label>
                                <input type="text" name="mobile_number" id="mobile_number" class="form-control"
                                       value="{{ old('mobile_number', $student->mobile_number) }}" placeholder="09XXXXXXXXX">
                            </div>
                            <div class="col-md-6">
                                <label for="address" class="form-label">Home address</label>
                                <input type="text" name="address" id="address" class="form-control"
                                       value="{{ old('address', $student->address) }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="student-edit-panel">
                    <div class="student-edit-panel__head">School enrollment</div>
                    <div class="student-edit-panel__body">
                        <div class="row g-3">
                            @include('students.partials.educational-fields', [
                                'programs' => $programs,
                                'schoolSetup' => $schoolSetup ?? [],
                                'educationalLevel' => $levelValue,
                                'year' => old('year', $student->year),
                                'course' => old('course', $student->course),
                                'section' => old('section', $student->section),
                                'sex' => old('sex', $student->sex),
                            ])
                        </div>
                    </div>
                </div>

                <div class="student-edit-panel">
                    <div class="student-edit-panel__head">Emergency contact</div>
                    <div class="student-edit-panel__body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="emergency_person" class="form-label">Contact name</label>
                                <input type="text" name="emergency_person" id="emergency_person" class="form-control"
                                       value="{{ old('emergency_person', $student->emergency_person) }}">
                            </div>
                            <div class="col-md-4">
                                <label for="emergency_relationship" class="form-label">Relationship</label>
                                <input type="text" name="emergency_relationship" id="emergency_relationship" class="form-control"
                                       value="{{ old('emergency_relationship', $student->emergency_relationship) }}">
                            </div>
                            <div class="col-md-4">
                                <label for="emergency_number" class="form-label">Contact number</label>
                                <input type="text" name="emergency_number" id="emergency_number" class="form-control"
                                       value="{{ old('emergency_number', $student->emergency_number) }}">
                            </div>
                            <div class="col-12">
                                <label for="emergency_address" class="form-label">Emergency address</label>
                                <textarea name="emergency_address" id="emergency_address" class="form-control" rows="2">{{ old('emergency_address', $student->emergency_address) }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="student-edit-panel">
                    <div class="student-edit-panel__head">Photo &amp; signature</div>
                    <div class="student-edit-panel__body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="profile_picture" class="form-label">Profile picture</label>
                                <label class="student-edit-upload">
                                    <input type="file" name="profile_picture" id="profile_picture" accept="image/jpeg,image/png,image/jpg">
                                    <span class="student-edit-upload__label">Choose new photo</span>
                                    <span class="student-edit-upload__hint">JPG or PNG, max 2 MB. Leave empty to keep current.</span>
                                </label>
                                @error('profile_picture')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Signature</label>
                                @if($student->student_signature)
                                    <p class="student-edit-hint mb-2">Current signature — draw below to replace:</p>
                                    <img src="{{ asset($student->student_signature) }}" alt="Current signature" class="student-edit-sig-preview">
                                @endif
                                <div class="signature-wrap">
                                    <canvas id="studentSignaturePad"></canvas>
                                </div>
                                <input type="hidden" name="student_signature" id="studentSignatureInput">
                                <button type="button" id="clearStudentSignature" class="btn btn-sm btn-outline-secondary mt-2">Clear new signature</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="student-edit-actions">
                    <a href="{{ route('students.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
(function () {
    const fileInput = document.getElementById('profile_picture');
    const preview = document.getElementById('profilePreview');
    const placeholder = document.getElementById('profilePreviewPlaceholder');

    fileInput?.addEventListener('change', function () {
        const file = fileInput.files?.[0];
        if (!file || !preview) return;
        preview.src = URL.createObjectURL(file);
        preview.classList.remove('d-none');
        placeholder?.classList.add('d-none');
    });

    const canvas = document.getElementById('studentSignaturePad');
    const input = document.getElementById('studentSignatureInput');
    if (!canvas || typeof SignaturePad === 'undefined') return;

    const signaturePad = new SignaturePad(canvas, { backgroundColor: 'rgb(255, 255, 255)' });

    function resizeCanvas() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const data = signaturePad.isEmpty() ? null : signaturePad.toData();
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = 150 * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        canvas.style.width = '100%';
        canvas.style.height = '150px';
        signaturePad.clear();
        if (data) signaturePad.fromData(data);
    }

    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    document.getElementById('clearStudentSignature')?.addEventListener('click', () => {
        signaturePad.clear();
        input.value = '';
    });

    document.getElementById('studentForm')?.addEventListener('submit', () => {
        if (!signaturePad.isEmpty()) {
            input.value = signaturePad.toDataURL();
        }
    });
})();
</script>
@endsection
