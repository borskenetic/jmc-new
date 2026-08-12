const { v4: uuidv4 } = require('uuid');
const {
  findStudentByToken,
  getSettings,
  updateStudentLastLog,
  insertLocalLog,
  countPending,
  setSyncState,
} = require('./db');

const TZ = 'Asia/Manila';

/** Philippine local time with +08:00 (avoids UTC offset in cloud uploads). */
function manilaLocalIso(date = new Date()) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: TZ,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(date)
      .map((p) => [p.type, p.value])
  );

  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}+08:00`;
}

function manilaParts(date = new Date()) {
  return Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: TZ,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(date)
      .map((p) => [p.type, p.value])
  );
}

function isInStatus(status) {
  return status != null && String(status).trim().toLowerCase() === 'in';
}

function isOutStatus(status) {
  return status != null && String(status).trim().toLowerCase() === 'out';
}

function startOfDay(date) {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  return d;
}

function endOfDay(date) {
  const d = new Date(date);
  d.setHours(23, 59, 59, 999);
  return d;
}

function sameManilaDay(a, b) {
  const pa = manilaParts(a);
  const pb = manilaParts(b);
  return pa.year === pb.year && pa.month === pb.month && pa.day === pb.day;
}

function closeStaleOpenIn(student) {
  if (!student.last_log_status || !isInStatus(student.last_log_status) || !student.last_log_scanned_at) {
    return student;
  }

  const last = new Date(student.last_log_scanned_at);
  const todayStart = startOfDay(new Date());

  if (startOfDay(last) >= todayStart) {
    return student;
  }

  const outAt = manilaLocalIso(endOfDay(last));
  updateStudentLastLog(student.cloud_id, 'OUT', outAt);

  return {
    ...student,
    last_log_status: 'OUT',
    last_log_scanned_at: outAt,
  };
}

function cooldownMinutes(settings) {
  if (settings?.scan_cooldown_minutes === undefined || settings?.scan_cooldown_minutes === null) {
    return 10;
  }
  const n = Number(settings.scan_cooldown_minutes);
  if (!Number.isFinite(n) || n < 0) return 10;
  return Math.floor(n);
}

function cooldownBlock(student, settings, at = new Date()) {
  const minutes = cooldownMinutes(settings);
  if (minutes <= 0 || !student.last_log_scanned_at) {
    return null;
  }

  const last = new Date(student.last_log_scanned_at);
  if (Number.isNaN(last.getTime())) {
    return null;
  }

  const cooldownMs = minutes * 60 * 1000;
  const elapsed = at.getTime() - last.getTime();
  if (elapsed >= cooldownMs) {
    return null;
  }

  const waitMs = cooldownMs - elapsed;
  const waitMinutes = Math.max(1, Math.ceil(waitMs / 60000));

  return {
    type: 'scan_cooldown',
    message: `Please wait ${waitMinutes} more minute${waitMinutes === 1 ? '' : 's'} before scanning again.`,
    retry_after_minutes: waitMinutes,
    cooldown_minutes: minutes,
    student: studentPayload(student),
  };
}

function formatDisplayTime(iso) {
  return new Date(iso).toLocaleString('en-US', {
    timeZone: TZ,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: 'numeric',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
  });
}

function studentPayload(student) {
  return {
    id: student.cloud_id,
    firstname: student.firstname,
    lastname: student.lastname,
    profile_picture: student.profile_picture,
    year: student.year,
    educational_level: student.educational_level,
  };
}

function outAllowedFrom(settings) {
  return String(settings?.student_schedule?.out_allowed_from || '11:00');
}

function outAllowedFromLabel(settings) {
  const [hh, mm] = outAllowedFrom(settings).split(':').map((n) => Number(n));
  const d = new Date();
  d.setHours(hh || 11, mm || 0, 0, 0);
  return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

function isOutAllowedNow(settings, at = new Date()) {
  const [hh, mm] = outAllowedFrom(settings).split(':').map((n) => Number(n));
  const parts = manilaParts(at);
  const minutesNow = Number(parts.hour) * 60 + Number(parts.minute);
  const minutesAllowed = (Number.isFinite(hh) ? hh : 11) * 60 + (Number.isFinite(mm) ? mm : 0);
  return minutesNow >= minutesAllowed;
}

/** One IN + one OUT per student per day. */
function dailyDecision(student, settings, at = new Date()) {
  const lastAt = student.last_log_scanned_at ? new Date(student.last_log_scanned_at) : null;
  const lastToday = lastAt && !Number.isNaN(lastAt.getTime()) && sameManilaDay(lastAt, at);

  if (!lastToday || !student.last_log_status) {
    return { next_status: 'IN', blocked: false };
  }

  if (isInStatus(student.last_log_status)) {
    if (!isOutAllowedNow(settings, at)) {
      return {
        next_status: 'OUT',
        blocked: true,
        type: 'out_too_early',
        message: `Check-out is only allowed from ${outAllowedFromLabel(settings)} onward.`,
        allowed_after: outAllowedFromLabel(settings),
      };
    }
    return { next_status: 'OUT', blocked: false };
  }

  if (isOutStatus(student.last_log_status)) {
    return {
      next_status: null,
      blocked: true,
      type: 'already_complete',
      message: 'This student already has IN and OUT recorded for today.',
    };
  }

  return { next_status: 'IN', blocked: false };
}

function previewScan(rawToken) {
  const settings = getSettings();

  let student = findStudentByToken(rawToken);

  if (!student) {
    return {
      type: 'error',
      message: 'ID not recognized. Sync the roster when online, or use the online gate for visitors.',
    };
  }

  student = closeStaleOpenIn(student);

  const cooldown = cooldownBlock(student, settings);
  if (cooldown) {
    return cooldown;
  }

  const decision = dailyDecision(student, settings);
  if (decision.blocked) {
    if (decision.type === 'out_too_early') {
      return {
        type: 'early_out_blocked',
        message: decision.message,
        allowed_after: decision.allowed_after,
        student: studentPayload(student),
      };
    }
    return {
      type: 'error',
      message: decision.message || 'Scan not allowed.',
      student: studentPayload(student),
    };
  }

  return {
    type: 'student',
    next_status: decision.next_status,
    student_id: student.cloud_id,
    section_picker_enabled: Boolean(settings.section_picker_enabled),
    logout_feedback_enabled: Boolean(settings.logout_feedback_enabled),
    student: studentPayload(student),
    attendance_sections: settings.attendance_sections || [],
  };
}

function isLateAt(scannedAtIso, settings) {
  const schedule = settings?.student_schedule || {};
  const inTime = String(schedule.in_time || '07:30');
  const grace = Math.max(0, Number(schedule.grace_minutes ?? 10) || 0);
  const [hh, mm] = inTime.split(':').map((n) => Number(n));
  if (!Number.isFinite(hh) || !Number.isFinite(mm)) {
    return false;
  }

  const scanned = new Date(scannedAtIso);
  const cutoff = new Date(scanned);
  cutoff.setHours(hh, mm, 0, 0);
  cutoff.setMinutes(cutoff.getMinutes() + grace);

  return scanned.getTime() > cutoff.getTime();
}

function recordScan(rawToken, section = null) {
  const preview = previewScan(rawToken);
  if (preview.type === 'scan_cooldown') {
    const err = new Error(preview.message || 'Please wait before scanning again.');
    err.code = 'scan_cooldown';
    err.payload = preview;
    throw err;
  }
  if (preview.type === 'early_out_blocked') {
    const err = new Error(preview.message || 'Check-out not allowed yet.');
    err.code = 'early_out_blocked';
    err.payload = preview;
    throw err;
  }
  if (preview.type !== 'student') {
    throw new Error(preview.message || 'Scan not allowed.');
  }

  const settings = getSettings();
  const sections = settings.attendance_sections || [];
  if (section && !sections.includes(section)) {
    throw new Error('Invalid section selected.');
  }

  const student = findStudentByToken(rawToken);
  const status = preview.next_status;
  const scannedAt = manilaLocalIso();
  const clientUuid = uuidv4();
  const isLate = status === 'IN' && isLateAt(scannedAt, settings);

  insertLocalLog({
    client_uuid: clientUuid,
    cloud_student_id: student.cloud_id,
    scan_token: String(rawToken).trim().replace(/\r/g, ''),
    status,
    section: section || null,
    gate: null,
    scanned_at: scannedAt,
  });

  updateStudentLastLog(student.cloud_id, status, scannedAt);
  setSyncState({ pending_count: countPending() });

  return {
    status,
    is_late: isLate,
    designation: isLate ? 'LATE' : null,
    scanned_at: formatDisplayTime(scannedAt),
    client_uuid: clientUuid,
    logout_feedback_enabled: Boolean(settings.logout_feedback_enabled),
  };
}

module.exports = {
  previewScan,
  recordScan,
  formatDisplayTime,
};
