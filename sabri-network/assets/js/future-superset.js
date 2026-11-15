(() => {
  'use strict';
  const root = document.getElementById('sn-future-workspace');
  if (!root) return;
  const cfg = window.SN_FUTURE_CONFIG || {};
  const status = document.getElementById('snf-status');
  const reminderList = document.getElementById('snf-reminders');
  const refreshReminders = document.getElementById('snf-refresh-reminders');

  const uuid = () => {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID().toLowerCase();
    if (!window.crypto?.getRandomValues) throw new Error('Secure random identifiers are unavailable.');
    const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(v => v.toString(16).padStart(2, '0'));
    return `${hex.slice(0,4).join('')}-${hex.slice(4,6).join('')}-${hex.slice(6,8).join('')}-${hex.slice(8,10).join('')}-${hex.slice(10).join('')}`;
  };
  const api = async (path, options = {}) => {
    const headers = {'X-WP-Nonce': cfg.nonce || ''};
    const method = String(options.method || 'GET').toUpperCase();
    let body = options.body;
    if (!['GET', 'HEAD'].includes(method)) {
      const key = String(options.idempotencyKey || uuid()).toLowerCase();
      headers['Idempotency-Key'] = key;
      if (body instanceof FormData) {
        if (!body.has('client_id')) body.set('client_id', key);
      } else {
        body = {...(body || {}), client_id: body?.client_id || key};
      }
    }
    if (body && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    const response = await fetch(`${cfg.restUrl || ''}${String(path).replace(/^\//, '')}`, {
      credentials: 'same-origin', method, headers, body
    });
    let data = {};
    try { data = await response.json(); } catch (_) { /* Fail below if HTTP error. */ }
    if (!response.ok) throw new Error(data.message || 'The advanced communication request failed.');
    return data;
  };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
  const announce = (message, error = false) => {
    status.className = error ? 'snf-status snf-error' : 'snf-status';
    status.textContent = message;
  };
  const featureList = items => `<div class="snf-feature-list">${items.map(item =>
    `<article class="snf-feature"><div><strong>${esc(item.id)} — ${esc(item.label)}</strong><small>${esc(item.phase)}${item.provider ? ` · provider: ${esc(item.provider)}` : ''}</small></div><span class="snf-badge snf-${esc(item.status)}">${esc(item.status)}</span></article>`
  ).join('')}</div>`;

  async function loadCapabilities() {
    try {
      const data = await api('future/capabilities');
      status.innerHTML = `<strong>${Number(data.feature_count || 0)} advanced File 17 capabilities</strong>${featureList(data.items || [])}`;
      status.className = 'snf-status';
    } catch (error) { announce(error.message, true); }
  }

  // An HTML datetime-local is an unqualified wall clock time. Browser Date()
  // otherwise silently shifts DST gaps and guesses one of two repeated instants.
  const localClock = date => [
    date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0')
  ].join('-') + 'T' + [
    String(date.getHours()).padStart(2, '0'),
    String(date.getMinutes()).padStart(2, '0')
  ].join(':');

  function exactLocalIso(value) {
    const selected = String(value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(selected))
      throw new Error('Select a complete local date and time.');
    const date = new Date(selected);
    if (!Number.isFinite(date.getTime()) || localClock(date) !== selected)
      throw new Error('This local time does not exist in your device timezone. Choose another time.');
    // Cover common 30-, 60-, 90- and 120-minute backward clock changes.
    for (const delta of [-120, -90, -60, -30, 30, 60, 90, 120]) {
      if (localClock(new Date(date.getTime() + delta * 60000)) === selected)
        throw new Error('This local time occurs twice after a clock change. Choose an unambiguous time.');
    }
    if (date.getTime() <= Date.now())
      throw new Error('Choose a time in the future.');
    return date.toISOString(); // Explicit instant; server retains UTC source of truth.
  }
  const fromUtc = utc => {
    const normalized = String(utc || '').replace(' ', 'T');
    const date = new Date(normalized.endsWith('Z') ? normalized : `${normalized}Z`);
    return Number.isFinite(date.getTime()) ? date : null;
  };
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = String(text);
    if (className) element.className = className;
    return element;
  };
  const formatLocal = utc => {
    const date = fromUtc(utc);
    return date ? date.toLocaleString() : 'Time unavailable';
  };

  async function loadReminders() {
    if (!reminderList) return;
    reminderList.textContent = 'Loading reminders…';
    if (refreshReminders) refreshReminders.disabled = true;
    try {
      const response = await api('future/reminders');
      const items = Array.isArray(response.items) ? response.items : [];
      const container = node('div', undefined, 'snf-reminder-items');
      if (!items.length) container.appendChild(node('p', 'No reminders yet.'));
      items.forEach(item => {
        const id = Number(item.id);
        const version = Number(item.version);
        const state = String(item.state || 'unknown');
        const card = node('article', undefined, 'snf-reminder');
        const heading = node('h4', String(item.data?.label || 'Reminder'));
        card.appendChild(heading);
        card.appendChild(node('p', `Conversation #${Number(item.scope_id) || 0} · ${state} · ${formatLocal(item.expires_at)}`));
        // Only render the user's own saved reminder label and metadata.
        // Never expose the underlying private message text in this projection.
        if (state === 'active' && Number.isSafeInteger(id) && id > 0) {
          const controls = node('div', undefined, 'snf-reminder-actions');
          const reschedule = node('button', 'Reschedule');
          const cancel = node('button', 'Cancel');
          reschedule.type = 'button';
          cancel.type = 'button';
          const validVersion = Number.isSafeInteger(version) && version > 0;
          if (!validVersion) {
            reschedule.disabled = true;
            cancel.disabled = true;
            card.appendChild(node('p', 'Reminder version unavailable. Refresh before editing.'));
          }
          reschedule.setAttribute('aria-label', `Reschedule reminder ${id}`);
          cancel.setAttribute('aria-label', `Cancel reminder ${id}`);
          reschedule.addEventListener('click', () => {
            if (!validVersion) return;
            const oldForm = card.querySelector('form');
            if (oldForm) { oldForm.remove(); return; }
            const form = node('form', undefined, 'snf-reschedule-form');
            const label = node('label', 'New local reminder time');
            const input = node('input');
            input.name = 'remind_at';
            input.type = 'datetime-local';
            input.required = true;
            const previous = fromUtc(item.expires_at);
            if (previous) input.value = localClock(previous);
            label.appendChild(input);
            const submit = node('button', 'Save new time');
            submit.type = 'submit';
            form.append(label, submit);
            form.addEventListener('submit', async event => {
              event.preventDefault();
              submit.disabled = true;
              try {
                const remindAt = exactLocalIso(input.value);
                await api(`future/reminders/${id}`, {
                  method: 'POST', body: {remind_at: remindAt, expected_version: version}
                });
                announce('Reminder rescheduled.');
                await loadReminders();
              } catch (error) { announce(error.message, true); }
              finally { submit.disabled = false; }
            });
            card.appendChild(form);
            input.focus();
          });
          cancel.addEventListener('click', async () => {
            if (!validVersion) return;
            cancel.disabled = true;
            try {
              await api(`future/reminders/${id}`, {
                method: 'DELETE', body: {expected_version: version}
              });
              announce('Reminder cancelled.');
              await loadReminders();
            } catch (error) { announce(error.message, true); }
            finally { cancel.disabled = false; }
          });
          controls.append(reschedule, cancel);
          card.appendChild(controls);
        }
        container.appendChild(card);
      });
      reminderList.replaceChildren(container);
    } catch (error) {
      reminderList.textContent = 'Reminder list unavailable. Refresh to try again.';
      announce(error.message, true);
    } finally {
      if (refreshReminders) refreshReminders.disabled = false;
    }
  }

  const bind = (id, handler) => {
    const form = document.getElementById(id);
    if (!form) return;
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      try {
        await handler(new FormData(form));
        announce('Saved successfully.');
        if (id !== 'snf-lock-form') form.reset();
      } catch (error) { announce(error.message, true); }
      finally { if (button) button.disabled = false; }
    });
  };
  bind('snf-lock-form', data => api(`future/conversation-locks/${Number(data.get('conversation_id'))}`, {
    method: 'POST', body: {enabled: data.get('enabled') === 'on'}
  }));
  // Keep one client-owned request identity across an ambiguous lost response.
  // A confirmed success or genuinely changed form starts a new operation.
  let pendingReminderCreation = null;
  bind('snf-reminder-form', async data => {
    const body = {
      conversation_id: Number(data.get('conversation_id')),
      remind_at: exactLocalIso(data.get('remind_at')),
      label: String(data.get('label') || '')
    };
    const fingerprint = JSON.stringify(body);
    if (!pendingReminderCreation || pendingReminderCreation.fingerprint !== fingerprint) {
      pendingReminderCreation = {fingerprint, idempotencyKey: uuid()};
    }
    await api('future/reminders', {
      method: 'POST', body,
      idempotencyKey: pendingReminderCreation.idempotencyKey
    });
    pendingReminderCreation = null;
    await loadReminders();
  });
  bind('snf-template-form', data => api('future/templates', {
    method: 'POST', body: {title: String(data.get('title') || ''), body: String(data.get('body') || '')}
  }));
  if (refreshReminders) refreshReminders.addEventListener('click', loadReminders);
  loadCapabilities();
  loadReminders();
})();
