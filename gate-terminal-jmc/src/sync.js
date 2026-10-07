const fs = require('fs');
const path = require('path');
const {
  getSyncState,
  setSyncState,
  saveSettings,
  upsertStudent,
  getPendingLogs,
  markSynced,
  countPending,
  applyRemoteLog,
} = require('./db');

function loadConfig() {
  const configPath = path.join(__dirname, '..', 'config.json');
  if (!fs.existsSync(configPath)) {
    throw new Error('Missing gate-terminal/config.json — copy config.example.json and set cloud_url + device_token.');
  }
  return JSON.parse(fs.readFileSync(configPath, 'utf8'));
}

function apiBase(config) {
  return String(config.cloud_url || '').replace(/\/$/, '') + '/api/gate';
}

async function cloudFetch(config, route, options = {}) {
  const url = apiBase(config) + route;
  const headers = {
    Accept: 'application/json',
    Authorization: `Bearer ${config.device_token}`,
    ...(options.headers || {}),
  };

  const timeoutMs = Number(config.fetch_timeout_ms) > 0
    ? Number(config.fetch_timeout_ms)
    : 120000;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  let response;
  try {
    response = await fetch(url, {
      ...options,
      headers,
      signal: controller.signal,
    });
  } catch (error) {
    if (error?.name === 'AbortError') {
      throw new Error(`Cloud request timed out after ${timeoutMs}ms`);
    }
    throw error;
  } finally {
    clearTimeout(timer);
  }

  const text = await response.text();
  let body = null;
  try {
    body = text ? JSON.parse(text) : null;
  } catch {
    body = { message: text };
  }

  if (!response.ok) {
    const message = body?.message || `HTTP ${response.status}`;
    throw new Error(message);
  }

  return body;
}

async function pullRoster(config) {
  const state = getSyncState();
  const since = state.last_pull_at ? `?since=${encodeURIComponent(state.last_pull_at)}` : '';
  const payload = await cloudFetch(config, `/roster${since}`);

  if (payload.settings) {
    saveSettings(payload.settings);
  }

  for (const student of payload.students || []) {
    upsertStudent(student);
  }

  for (const log of payload.logs_since || []) {
    applyRemoteLog(log.student_id, log.status, log.scanned_at);
  }

  setSyncState({
    last_pull_at: payload.server_time,
    online: 1,
    pending_count: countPending(),
  });

  return {
    students: (payload.students || []).length,
    full_snapshot: Boolean(payload.full_snapshot),
  };
}

async function pushAttendanceBatch(config) {
  const pending = getPendingLogs();
  if (pending.length === 0) {
    return { accepted: 0, rejected: 0, processed: 0, done: true };
  }

  const body = {
    scans: pending.map((row) => ({
      client_uuid: row.client_uuid,
      scan_token: row.scan_token,
      status: row.status,
      section: row.section,
      gate: row.gate || null,
      scanned_at: row.scanned_at,
    })),
  };

  const result = await cloudFetch(config, '/attendance', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  // Mark both accepted and rejected so failed rows cannot block the queue forever.
  const processedUuids = (result.results || [])
    .map((row) => row.client_uuid)
    .filter(Boolean);

  if (processedUuids.length) {
    markSynced(processedUuids);
  } else if (pending.length) {
    // Server responded without per-row results — still clear this batch to avoid a stuck loop.
    markSynced(pending.map((row) => row.client_uuid));
  }

  setSyncState({
    online: 1,
    pending_count: countPending(),
  });

  return {
    accepted: result.accepted || 0,
    rejected: result.rejected || 0,
    processed: processedUuids.length || pending.length,
    done: countPending() === 0,
  };
}

async function pushAttendance(config) {
  let accepted = 0;
  let rejected = 0;
  let batches = 0;
  const maxBatches = Number(config.push_batches_per_cycle) > 0
    ? Number(config.push_batches_per_cycle)
    : 20;

  while (batches < maxBatches) {
    const batch = await pushAttendanceBatch(config);
    accepted += batch.accepted;
    rejected += batch.rejected;
    batches += 1;
    if (batch.done || batch.processed === 0) {
      break;
    }
  }

  return { accepted, rejected, batches };
}

async function checkHealth(config) {
  await cloudFetch(config, '/health');
  setSyncState({ online: 1, pending_count: countPending() });
  return true;
}

let syncInFlight = null;

async function runSyncCycle(config) {
  if (syncInFlight) {
    return syncInFlight;
  }

  syncInFlight = (async () => {
    let push = { accepted: 0, rejected: 0, batches: 0 };
    let pullError = null;
    let pushError = null;

    try {
      await checkHealth(config);
    } catch (error) {
      setSyncState({ online: 0, pending_count: countPending() });
      return { ok: false, error: error.message };
    }

    // Upload first so a slow/failing roster pull cannot starve the backlog.
    try {
      push = await pushAttendance(config);
    } catch (error) {
      pushError = error.message;
    }

    try {
      await pullRoster(config);
    } catch (error) {
      pullError = error.message;
    }

    const pending = countPending();
    setSyncState({
      online: pushError ? 0 : 1,
      pending_count: pending,
    });

    if (pushError) {
      return { ok: false, error: pushError, push, pull_error: pullError };
    }

    return {
      ok: true,
      push,
      pull_error: pullError,
    };
  })();

  try {
    return await syncInFlight;
  } finally {
    syncInFlight = null;
  }
}

module.exports = {
  loadConfig,
  runSyncCycle,
  pullRoster,
  pushAttendance,
  checkHealth,
  cloudFetch,
};
