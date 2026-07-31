<!DOCTYPE html>
<html lang="en">
<head>
  <title>{{ config('app.name') }} — Gate Terminal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="{{ \App\Support\Branding::stylesheetUrl() }}">
  <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/attendance/scan.css') }}">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body class="scan-kiosk-page">

<header>
  <div class="header">
    <div class="logo-title">
      <img src="{{ asset('images/pantasLogo.png') }}" alt="Logo">
    </div>
    <div class="header-actions">
      @if(config('face.enabled'))
        <a href="{{ route('attendance.face') }}" class="scan-header-link">Face gate terminal</a>
      @endif
    </div>
  </div>
</header>

<div class="main">
  <div class="sidebar" id="scanSidebar">
    <div class="date" id="currentDate">Date</div>
    <div class="time" id="currentTime">--:--:--</div>
    <div class="profile-pic">
      <img src="{{ asset('images/2x2_undifined_gender.jpg') }}" alt="Default Profile">
    </div>
    <div id="earlyOutAlarm" class="early-out-alarm" hidden aria-live="assertive" role="alert">
      <div class="early-out-alarm__icon">⚠</div>
      <div class="early-out-alarm__title">Early checkout not allowed</div>
      <p class="early-out-alarm__message" id="earlyOutAlarmMessage"></p>
      <p class="early-out-alarm__hint">Allowed after <strong id="earlyOutAlarmTime">{{ $earlyDepartureCutoffLabel ?? '4:00 PM' }}</strong></p>
    </div>
  </div>
  
  <div class="sidebar-divider" id="scanDivider">
    <div class="scan-name-display" id="scanNameDisplay" hidden>
      <div class="scan-name-welcome">Welcome,</div>
      <div class="scan-name-text" id="scanNameText"></div>
      <div class="scan-status-badge" id="scanStatusBadge"></div>
      <div class="scan-name-timestamp" id="scanNameTimestamp"></div>
    </div>
  </div>

 

  <div class="right-content">
    <form id="scanForm">
      @csrf
      <textarea name="qrcode" id="qrcode" style="opacity:0; position:absolute;" autofocus autocomplete="off"></textarea>
    </form>
    <video muted autoplay loop controls class="ads-vid">
      <source src="{{ asset('videos/area51_product_slideshow.mp4') }}" type="video/mp4">
    </video>
  </div>
</div>

<footer>
  <div class="footer1">
    <div class="footer-logo">
      <div class="marquee-container">
        <div class="marquee">
          <span>Welcome to {{ config('app.name') }}</span>
        </div>
 
      </div>
    </div>
  </div>
</footer>

<div id="sectionModal" class="section-modal" aria-hidden="true">
  <div class="modal-content section-picker-modal">
    <h2>Select section</h2>
    <div class="section-buttons" id="sectionButtons" data-count="{{ count($attendanceSections ?? []) }}">
      @forelse($attendanceSections ?? [] as $section)
        <button type="button" data-section="{{ $section }}">{{ $section }}</button>
      @empty
        <p class="section-empty-msg">No sections configured.</p>
      @endforelse
    </div>
  </div>
</div>

<audio id="scanAlarmSound" src="{{ asset('sounds/alarm.wav') }}" preload="auto"></audio>

<div id="feedbackModal" class="section-modal" aria-hidden="true">
  <div class="modal-content feedback-card">
    <h2>How was your experience?</h2>
    <div class="feedback-options">
      <button type="button" data-rating="excellent">😊<span>Excellent</span></button>
      <button type="button" data-rating="good">🙂<span>Good</span></button>
      <button type="button" data-rating="medium">😐<span>Medium</span></button>
      <button type="button" data-rating="poor">🙁<span>Poor</span></button>
      <button type="button" data-rating="very_bad">😠<span>Very Bad</span></button>
    </div>
    <button type="button" id="declineFeedback" class="decline-btn">Skip</button>
  </div>
</div>

<script>
  const LOGOUT_FEEDBACK_ENABLED = @json($logoutFeedbackEnabled ?? false);
  const SECTION_PICKER_ENABLED = @json($sectionPickerEnabled ?? false);
  const HAS_ATTENDANCE_SECTIONS = @json(count($attendanceSections ?? []) > 0);
  const EARLY_DEPARTURE_ENABLED = @json($earlyDepartureEnabled ?? true);
  const feedbackModal = document.getElementById('feedbackModal');
  const earlyOutAlarm = document.getElementById('earlyOutAlarm');
  const earlyOutAlarmMessage = document.getElementById('earlyOutAlarmMessage');
  const earlyOutAlarmTime = document.getElementById('earlyOutAlarmTime');
  const scanSidebar = document.getElementById('scanSidebar');
  const scanAlarmSound = document.getElementById('scanAlarmSound');
  const sectionModal = document.getElementById('sectionModal');
  let selectedStudent = null;
  let selectedVisitor = null;
  let currentStudentId = null;
  let currentVisitorId = null;
  let clearDisplayTimer = null;

  document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('qrcode');
    const profileImg = document.querySelector('.profile-pic img');
    const sidebar = document.querySelector('.sidebar');
    let isCooldown = false;

    setInterval(() => input.focus(), 100);
    input.focus();

    function showDividerName(name, status, timestamp, isOut) {
      const display = document.getElementById('scanNameDisplay');
      const nameEl = document.getElementById('scanNameText');
      const badgeEl = document.getElementById('scanStatusBadge');
      const tsEl = document.getElementById('scanNameTimestamp');
      if (!display) return;
      nameEl.textContent = name;
      badgeEl.textContent = status;
      badgeEl.className = 'scan-status-badge' + (isOut ? ' scan-status-out' : '');
      tsEl.textContent = timestamp || '';
      display.removeAttribute('hidden');
    }

    function hideDividerName() {
      const display = document.getElementById('scanNameDisplay');
      if (display) display.hidden = true;
    }

    function clearDisplay() {
      if (feedbackModal && feedbackModal.style.display === 'flex') return;
      profileImg.src = "{{ asset('images/2x2_undifined_gender.jpg') }}";
      document.querySelectorAll('.name-box').forEach(box => box.remove());
      hideDividerName();
      hideEarlyOutAlarm();
      selectedStudent = null;
      selectedVisitor = null;
      currentStudentId = null;
      currentVisitorId = null;
    }

    function playAlarmSound() {
      if (!scanAlarmSound) return;
      scanAlarmSound.currentTime = 0;
      scanAlarmSound.play().catch(() => {});
    }

    function showEarlyOutAlarm(data) {
      if (!earlyOutAlarm) return;
      const student = data.student || {};
      const name = [student.firstname, student.lastname].filter(Boolean).join(' ');
      const year = student.year ? ` (${student.year})` : '';

      if (earlyOutAlarmMessage) {
        earlyOutAlarmMessage.textContent = data.message || 'Cannot check out before the allowed time.';
      }
      if (earlyOutAlarmTime && data.allowed_after) {
        earlyOutAlarmTime.textContent = data.allowed_after;
      }
      const hint = earlyOutAlarm?.querySelector('.early-out-alarm__hint');
      if (hint) {
        hint.hidden = !data.allowed_after;
        if (data.allowed_after) {
          hint.innerHTML = `Allowed after <strong id="earlyOutAlarmTime">${data.allowed_after}</strong>`;
        }
      }
      const title = earlyOutAlarm?.querySelector('.early-out-alarm__title');
      if (title) title.textContent = 'Early checkout not allowed';

      profileImg.src = profileUrl(student.profile_picture);

      const div = document.createElement('div');
      div.classList.add('name-box', 'name-box--blocked');
      div.innerHTML = `
        <div class="student-name">${name}${year}</div>
        <div class="label">Still checked in</div>
        <div class="status-button status-blocked">NOT ALLOWED</div>
      `;
      sidebar.appendChild(div);

      earlyOutAlarm.hidden = false;
      scanSidebar?.classList.add('sidebar--alarm');
      playAlarmSound();
      scheduleClear(8000);
    }

    function showCooldownAlarm(data) {
      if (!earlyOutAlarm) {
        showUnknownScanAlarm(data.message || 'Please wait before scanning again.');
        return;
      }
      const student = data.student || {};
      const name = [student.firstname, student.lastname].filter(Boolean).join(' ');
      const year = student.year ? ` (${student.year})` : '';
      const wait = data.retry_after_minutes;

      if (earlyOutAlarmMessage) {
        earlyOutAlarmMessage.textContent = data.message || 'Please wait before scanning again.';
      }
      if (earlyOutAlarmTime) {
        earlyOutAlarmTime.textContent = wait ? `${wait} min` : 'a few minutes';
      }
      const hint = earlyOutAlarm?.querySelector('.early-out-alarm__hint');
      if (hint) {
        hint.hidden = false;
        hint.innerHTML = wait
          ? `Try again in <strong>${wait} minute${wait === 1 ? '' : 's'}</strong>`
          : 'Try again in a few minutes';
      }
      const title = earlyOutAlarm?.querySelector('.early-out-alarm__title');
      if (title) title.textContent = 'Scan cooldown';

      profileImg.src = profileUrl(student.profile_picture);

      const div = document.createElement('div');
      div.classList.add('name-box', 'name-box--blocked');
      div.innerHTML = `
        <div class="student-name">${name || 'Student'}${year}</div>
        <div class="label">Too soon</div>
        <div class="status-button status-blocked">WAIT</div>
      `;
      sidebar.appendChild(div);

      earlyOutAlarm.hidden = false;
      scanSidebar?.classList.add('sidebar--alarm');
      playAlarmSound();
      scheduleClear(5000);
    }

    function showUnknownScanAlarm(message) {
      const div = document.createElement('div');
      div.classList.add('name-box', 'scan-error-box');
      div.innerHTML = `
        <div class="student-name">${message}</div>
        <div class="label">Not recognized</div>
        <div class="status-button status-blocked">UNKNOWN</div>
      `;
      sidebar.appendChild(div);
      scanSidebar?.classList.add('sidebar--alarm');
      playAlarmSound();
      scheduleClear(4000);
    }

    function hideEarlyOutAlarm() {
      earlyOutAlarm?.setAttribute('hidden', '');
      scanSidebar?.classList.remove('sidebar--alarm');
    }

    function scheduleClear(delayMs) {
      if (clearDisplayTimer) clearTimeout(clearDisplayTimer);
      clearDisplayTimer = setTimeout(clearDisplay, delayMs);
    }

    function showLogoutFeedback() {
      const enabled = LOGOUT_FEEDBACK_ENABLED;
      if (!enabled || !feedbackModal || !currentStudentId) {
        scheduleClear(2000);
        return;
      }
      if (clearDisplayTimer) {
        clearTimeout(clearDisplayTimer);
        clearDisplayTimer = null;
      }
      setTimeout(() => {
        feedbackModal.style.display = 'flex';
        feedbackModal.setAttribute('aria-hidden', 'false');
      }, 500);
    }

    function processVisitorLog(visitorId) {
      return fetch("{{ route('attendance.visitor') }}", {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': '{{ csrf_token() }}',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ visitor_id: visitorId }),
      });
    }

    function showStudentScanResult(student, status, scannedAt, meta = {}) {
      const isOut = String(status).toUpperCase() === 'OUT';
      const label = meta.sectionLabel || 'Name';
      const div = document.createElement('div');
      div.classList.add('name-box', 'scan-result-box');
      div.innerHTML = `
        <div class="student-name">${student.firstname} ${student.lastname}</div>
        <div class="label">${label}</div>
        <div class="status-button${isOut ? ' status-out' : ''}">${status}</div>
        <div class="timestamp">${scannedAt || ''}</div>
      `;
      sidebar.appendChild(div);
      showDividerName(`${student.firstname} ${student.lastname}`, status, scannedAt, isOut);
    }

    async function recordStudentScan(studentId, section, lookupData, sectionLabel) {
      const res = await fetch("{{ route('attendance.section') }}", {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': '{{ csrf_token() }}',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ student_id: studentId, section }),
      });

      let response = {};
      try {
        response = await res.json();
      } catch (e) {
        showUnknownScanAlarm('Could not record attendance. Please try again.');
        return;
      }

      if (res.status === 403) {
        showEarlyOutAlarm({
          message: response.message,
          allowed_after: response.allowed_after,
          student: selectedStudent,
        });
        return;
      }

      if (res.status === 429 || response.type === 'scan_cooldown') {
        showCooldownAlarm({
          ...response,
          student: response.student || selectedStudent,
        });
        return;
      }

      const status = response.status || lookupData?.next_status;
      if (!res.ok || !status) {
        const msg = response.message
          || (response.errors && Object.values(response.errors).flat()[0])
          || 'Could not record attendance.';
        showUnknownScanAlarm(msg);
        return;
      }

      showStudentScanResult(selectedStudent, status, response.scanned_at, { sectionLabel });

      if (String(status).toUpperCase() === 'OUT') {
        const feedbackOn = response.logout_feedback_enabled
          ?? lookupData?.logout_feedback_enabled
          ?? LOGOUT_FEEDBACK_ENABLED;
        if (feedbackOn) {
          showLogoutFeedback();
        } else {
          scheduleClear(2000);
        }
      } else {
        scheduleClear(3000);
      }
    }

    function showVisitorScanResult(visitor, status, timestamp) {
      const org = visitor.organization ? ` · ${visitor.organization}` : '';
      const div = document.createElement('div');
      div.classList.add('name-box', 'scan-result-box', 'scan-result-box--visitor');
      div.innerHTML = `
        <div class="student-name">${visitor.firstname} ${visitor.lastname}</div>
        <div class="label">Visitor${org}</div>
        <div class="status-button status-visitor">${status}</div>
        <div class="timestamp">${timestamp}</div>
      `;
      sidebar.appendChild(div);
      showDividerName(`${visitor.firstname} ${visitor.lastname}`, status, timestamp, status === 'OUT');
      scheduleClear(status === 'OUT' ? 2000 : 3000);
    }

    function profileUrl(path) {
      if (!path) return "{{ asset('images/2x2_undifined_gender.jpg') }}";
      path = path.replace(/^\//, '');
      if (!path.startsWith('images/')) {
        path = 'images/profile_pictures/' + path;
      }
      return "{{ asset('') }}" + path;
    }

    input.addEventListener('keypress', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      if (isCooldown) return;
      isCooldown = true;
      setTimeout(() => { isCooldown = false; }, 300);

      const formData = new FormData();
      formData.append('qrcode', input.value.trim().replace(/\r/g, ''));
      formData.append('_token', '{{ csrf_token() }}');

      fetch("{{ route('attendance.process') }}", { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
          if (feedbackModal && feedbackModal.style.display === 'flex') {
            closeFeedbackModal();
          }
          clearDisplay();

          if (data.type === 'early_out_blocked') {
            showEarlyOutAlarm(data);
            input.value = '';
            return;
          }

          if (data.type === 'scan_cooldown') {
            showCooldownAlarm(data);
            input.value = '';
            return;
          }

          if (data.type === 'visitor') {
            selectedVisitor = data.visitor;
            currentVisitorId = data.visitor_id;
            profileImg.src = "{{ asset('images/2x2_undifined_gender.jpg') }}";

            processVisitorLog(currentVisitorId)
              .then(res => res.json())
              .then(response => {
                showVisitorScanResult(selectedVisitor, response.status, response.scanned_at);
              });
            input.value = '';
            return;
          }

          if (data.type === 'student') {
            selectedStudent = data.student;
            currentStudentId = data.student_id;
            profileImg.src = profileUrl(data.student.profile_picture);

            if (data.next_status === 'OUT') {
              recordStudentScan(currentStudentId, null, data);
            } else {
              const sectionPickerOn = (data.section_picker_enabled ?? SECTION_PICKER_ENABLED) && HAS_ATTENDANCE_SECTIONS;
              if (sectionPickerOn) {
                sectionModal.style.display = 'flex';
                sectionModal.setAttribute('aria-hidden', 'false');
              } else {
                recordStudentScan(currentStudentId, null, data);
              }
            }
          } else if (data.type === 'error') {
            showUnknownScanAlarm(data.message || 'ID not recognized. Visitors must register first.');
          }

          input.value = '';
        })
        .catch(err => console.error(err));
    });

    document.querySelectorAll('.section-buttons button').forEach(btn => {
      btn.addEventListener('click', function () {
        if (!currentStudentId) return;

        sectionModal.style.display = 'none';
        sectionModal.setAttribute('aria-hidden', 'true');

        recordStudentScan(
          currentStudentId,
          this.dataset.section,
          { next_status: 'IN', logout_feedback_enabled: LOGOUT_FEEDBACK_ENABLED },
          this.dataset.section
        );
      });
    });

    function closeFeedbackModal() {
      if (!feedbackModal) return;
      feedbackModal.style.display = 'none';
      feedbackModal.setAttribute('aria-hidden', 'true');
    }

    function sendFeedback(rating = null, declined = 0) {
      if (!currentStudentId) {
        closeFeedbackModal();
        clearDisplay();
        input.focus();
        return;
      }

      fetch("{{ route('attendance.feedback.store') }}", {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': '{{ csrf_token() }}',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          student_id: currentStudentId,
          rating: rating,
          declined: declined ? 1 : 0,
        }),
      }).catch(err => console.error(err)).finally(() => {
        closeFeedbackModal();
        clearDisplay();
        input.focus();
      });
    }

    document.querySelectorAll('.feedback-options button').forEach(btn => {
      btn.addEventListener('click', function () {
        sendFeedback(this.dataset.rating, 0);
      });
    });

    document.getElementById('declineFeedback')?.addEventListener('click', function () {
      sendFeedback(null, 1);
    });

    function updateDateTime() {
      const now = new Date();
      const dateEl = document.getElementById('currentDate');
      const timeEl = document.getElementById('currentTime');
      if (dateEl && timeEl) {
        dateEl.textContent = now.toLocaleDateString('en-GB', {
          weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
        });
        timeEl.textContent = now.toLocaleTimeString('en-US');
      }
    }
    updateDateTime();
    setInterval(updateDateTime, 1000);
  });
</script>
</body>
</html>
