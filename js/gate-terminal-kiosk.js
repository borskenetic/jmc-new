/**
 * Gate terminal designation — per-kiosk gate selection with server-side occupancy.
 */
(function () {
  const STORAGE_TOKEN = 'attendance_terminal_token';
  const STORAGE_GATE = 'attendance_gate_terminal';

  const boundButtons = new WeakSet();

  function cfg() {
    return window.GATE_TERMINAL_CONFIG || {};
  }

  function headers() {
    const c = cfg();
    const metaCsrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    return {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-TOKEN': c.csrf || metaCsrf,
    };
  }

  function terminalToken() {
    let token = localStorage.getItem(STORAGE_TOKEN);
    if (!token) {
      token = (window.crypto && crypto.randomUUID)
        ? crypto.randomUUID()
        : 't-' + Date.now() + '-' + Math.random().toString(36).slice(2);
      localStorage.setItem(STORAGE_TOKEN, token);
    }
    return token;
  }

  function getGate() {
    return localStorage.getItem(STORAGE_GATE) || '';
  }

  function setGate(name) {
    if (!name) {
      localStorage.removeItem(STORAGE_GATE);
    } else {
      localStorage.setItem(STORAGE_GATE, name);
    }
    updateBadge();
  }

  function payload() {
    const gate = getGate();
    return gate ? { gate } : {};
  }

  function updateBadge() {
    const badge = document.getElementById('gateTerminalBadge');
    const label = document.getElementById('gateTerminalLabel');
    if (!badge || !label) return;

    const gate = getGate();
    if (!gate) {
      badge.hidden = true;
      return;
    }

    label.textContent = gate;
    badge.hidden = false;
  }

  function setLoading(isLoading) {
    const el = document.getElementById('gateTerminalLoading');
    if (el) el.hidden = !isLoading;
  }

  function openModal() {
    const modal = document.getElementById('gateTerminalModal');
    if (!modal) return;
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    refreshButtons();
  }

  function closeModal() {
    const modal = document.getElementById('gateTerminalModal');
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
  }

  async function fetchAvailable() {
    const c = cfg();
    if (!c.availableUrl) {
      throw new Error('Gate terminal is not configured.');
    }

    const url = new URL(c.availableUrl, window.location.origin);
    url.searchParams.set('terminal_token', terminalToken());

    const res = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
    if (!res.ok) {
      throw new Error('Failed to load gates (' + res.status + ')');
    }
    return res.json();
  }

  async function claimGate(gate) {
    const c = cfg();
    if (!c.claimUrl) {
      throw new Error('Gate terminal is not configured.');
    }

    const res = await fetch(c.claimUrl, {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify({ terminal_token: terminalToken(), gate }),
    });

    let data = {};
    try {
      data = await res.json();
    } catch (e) {
      data = {};
    }

    if (!res.ok) {
      throw new Error(data.message || 'Could not claim gate');
    }

    setGate(data.gate || gate);
    closeModal();
  }

  function bindGateButton(btn) {
    if (!btn || boundButtons.has(btn)) return;
    boundButtons.add(btn);

    btn.addEventListener('click', async () => {
      const gate = btn.dataset.gate;
      if (!gate) return;

      btn.disabled = true;
      try {
        await claimGate(gate);
      } catch (e) {
        alert(e.message || 'Gate unavailable. Try another.');
        refreshButtons();
      } finally {
        btn.disabled = false;
      }
    });
  }

  function bindExistingButtons() {
    document.querySelectorAll('#gateTerminalButtons button[data-gate]').forEach(bindGateButton);
  }

  function renderButtons(gates) {
    const container = document.getElementById('gateTerminalButtons');
    const empty = document.getElementById('gateTerminalEmpty');
    if (!container) return;

    container.innerHTML = '';
    const list = Array.isArray(gates) ? gates : [];

    container.dataset.count = String(list.length);

    if (list.length === 0) {
      if (empty) empty.hidden = false;
      return;
    }

    if (empty) empty.hidden = true;

    list.forEach((gate) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.dataset.gate = gate;
      btn.textContent = gate;
      container.appendChild(btn);
      bindGateButton(btn);
    });
  }

  async function refreshButtons() {
    setLoading(true);
    try {
      const data = await fetchAvailable();
      if (data.current_gate && !getGate()) {
        setGate(data.current_gate);
      }
      renderButtons(data.gates || []);
    } catch (e) {
      bindExistingButtons();
      const container = document.getElementById('gateTerminalButtons');
      const empty = document.getElementById('gateTerminalEmpty');
      const hasButtons = container && container.querySelectorAll('button[data-gate]').length > 0;
      if (empty) empty.hidden = hasButtons;
    } finally {
      setLoading(false);
    }
  }

  async function requireSelection() {
    updateBadge();

    if (getGate()) {
      try {
        await claimGate(getGate());
        return;
      } catch (e) {
        setGate('');
      }
    }

    openModal();
  }

  async function ping() {
    const c = cfg();
    if (!getGate() || !c.pingUrl) return;
    try {
      await fetch(c.pingUrl, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ terminal_token: terminalToken() }),
      });
    } catch (e) { /* ignore */ }
  }

  function bindChange() {
    document.getElementById('gateTerminalChange')?.addEventListener('click', (e) => {
      e.preventDefault();
      openModal();
    });
  }

  window.GateTerminalKiosk = {
    getGate,
    payload,
    requireSelection,
    refreshButtons,
    openModal,
    updateBadge,
  };

  function init() {
    bindChange();
    bindExistingButtons();
    requireSelection();
    setInterval(ping, 60000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
