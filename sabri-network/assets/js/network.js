(() => {
  'use strict';
  const cfg = window.SN_CONFIG || {};
  const root = document.getElementById('sn-network-root');
  if (!root || !cfg.isLoggedIn) return;

  const $ = (selector, scope = document) => scope.querySelector(selector);
  const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];
  const escapeHtml = (value = '') => String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const safeUrl = (value = '') => {
    try {
      const parsed = new URL(String(value), window.location.origin);
      return ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : '';
    } catch (_) { return ''; }
  };
  const formatTime = value => {
    if (!value) return '';
    try {
      const date = new Date(`${String(value).replace(' ', 'T')}Z`);
      return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(undefined, {dateStyle:'medium', timeStyle:'short'}).format(date);
    } catch (_) { return ''; }
  };
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`);

  const state = {
    user: null,
    privacy: {},
    capabilities: {},
    iceServers: [],
    conversations: [],
    activeConversation: null,
    messages: [],
    calls: [],
    notifications: [],
    currentTab: 'chats',
    pollTimer: null,
    signalTimer: null,
    currentCall: null,
    peer: null,
    localStream: null,
    signalAfter: 0,
    shownIncomingCall: 0,
    replyTo: 0,
    busy: false,
    modalReturnFocus: null,
    presence: new Map(),
    typingUsers: [],
    presenceTimer: null,
    typingStopTimer: null,
    lastTypingSentAt: 0,
    showArchived: false,
  };

  async function api(path, options = {}) {
    if (!navigator.onLine) throw new Error(cfg.strings?.offline || 'You appear to be offline.');
    const headers = {'X-WP-Nonce': cfg.nonce || ''};
    let body = options.body;
    if (body && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    const response = await fetch(`${cfg.restUrl}${String(path).replace(/^\//, '')}`, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: {...headers, ...(options.headers || {})},
      body,
      signal: options.signal,
    });
    let data = {};
    try { data = await response.json(); } catch (_) {}
    if (!response.ok) {
      const error = new Error(data.message || cfg.strings?.requestFailed || 'Network request failed.');
      error.code = data.code || 'request_failed';
      error.status = response.status;
      error.data = data.data || {};
      throw error;
    }
    return data;
  }

  function toast(message, type = 'info') {
    const container = $('#sn-toast-container');
    if (!container) return;
    const item = document.createElement('div');
    item.className = `sn-toast sn-toast-${type}`;
    item.textContent = message;
    container.append(item);
    requestAnimationFrame(() => item.classList.add('is-visible'));
    setTimeout(() => { item.classList.remove('is-visible'); setTimeout(() => item.remove(), 250); }, 4250);
  }

  function setLoading(container, label = 'Loading…') {
    if (container) container.innerHTML = `<div class="sn-loading" role="status"><span class="sn-spinner"></span>${escapeHtml(label)}</div>`;
  }

  function openModal(title, html, onReady) {
    const modal = $('#sn-modal');
    state.modalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    $('#sn-modal-title').textContent = title;
    $('#sn-modal-body').innerHTML = html;
    modal.hidden = false;
    document.body.classList.add('sn-modal-open');
    const close = $('[data-close-modal]', modal);
    close?.focus();
    if (onReady) onReady($('#sn-modal-body'));
  }

  function closeModal() {
    const modal = $('#sn-modal');
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    $('#sn-modal-body').replaceChildren();
    document.body.classList.remove('sn-modal-open');
    if (state.modalReturnFocus?.isConnected) state.modalReturnFocus.focus();
    state.modalReturnFocus = null;
  }

  $$('[data-close-modal]').forEach(el => el.addEventListener('click', closeModal));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closeModal();
      if (state.currentCall) endLocalCall(true);
      return;
    }
    const modal = $('#sn-modal');
    if (event.key === 'Tab' && modal && !modal.hidden) {
      const focusable = $$('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])', modal).filter(el => !el.hidden && el.offsetParent !== null);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });

  async function bootstrap() {
    try {
      const me = await api('me');
      state.user = me.user;
      state.privacy = me.privacy || {};
      state.capabilities = me.capabilities || {};
      state.iceServers = me.ice_servers || [];
      await Promise.all([loadConversations(), loadCalls(), loadNotifications(), sendPresence('online')]);
      renderSidebar();
      startPresenceHeartbeat();
      startPolling();
    } catch (error) {
      toast(error.message, 'error');
      const content = $('#sn-sidebar-content');
      if (content) content.innerHTML = `<div class="sn-error-state"><strong>Network unavailable</strong><p>${escapeHtml(error.message)}</p><a class="sn-btn" href="${escapeHtml(safeUrl(cfg.safeUrl || location.href))}">Open Safe Route</a></div>`;
    }
  }

  async function loadConversations() {
    const data = await api('conversations');
    state.conversations = data.conversations || [];
    if (state.activeConversation) {
      const summary = state.conversations.find(item => item.id === state.activeConversation.id);
      if (summary) state.activeConversation = {...state.activeConversation, ...summary};
    }
  }

  async function loadCalls() {
    const data = await api('calls');
    state.calls = data.calls || [];
    const incoming = state.calls.find(call => call.status === 'ringing' && call.member_status === 'invited');
    if (incoming && incoming.id !== state.shownIncomingCall && !state.currentCall) {
      state.shownIncomingCall = incoming.id;
      showIncomingCall(incoming);
    }
  }

  async function loadNotifications() {
    const data = await api('notifications');
    state.notifications = data.notifications || [];
    const unread = state.notifications.filter(item => !item.is_read).length;
    const badge = $('#sn-notification-badge');
    if (badge) {
      badge.hidden = unread === 0;
      badge.textContent = unread > 99 ? '99+' : String(unread);
    }
  }

  async function sendPresence(status = 'online') {
    if (!navigator.onLine) return;
    try { await api('presence', {method:'POST', body:{status}}); } catch (_) {}
  }

  function startPresenceHeartbeat() {
    clearInterval(state.presenceTimer);
    state.presenceTimer = setInterval(() => {
      if (!document.hidden) sendPresence('online');
    }, 45000);
  }

  async function loadConversationPresence() {
    if (!state.activeConversation) return;
    const ids = (state.activeConversation.member_ids || [])
      .filter(id => Number(id) > 0 && Number(id) !== Number(state.user?.id));
    if (!ids.length) {
      state.presence = new Map();
      updateConversationSubtitle();
      return;
    }
    try {
      const data = await api(`presence?user_ids=${encodeURIComponent(ids.join(','))}`);
      state.presence = new Map((data.presence || []).map(item => [Number(item.user_id), item]));
    } catch (_) { state.presence = new Map(); }
    updateConversationSubtitle();
  }

  async function loadTyping() {
    if (!state.activeConversation) return;
    try {
      const data = await api(`conversations/${state.activeConversation.id}/typing`);
      state.typingUsers = data.typing || [];
    } catch (_) { state.typingUsers = []; }
    updateConversationSubtitle();
  }

  function updateConversationSubtitle() {
    const subtitle = $('#sn-chat-subtitle');
    const conversation = state.activeConversation;
    if (!subtitle || !conversation) return;
    if (state.typingUsers.length) {
      const names = state.typingUsers.slice(0, 3).map(user => user.name).filter(Boolean);
      subtitle.textContent = `${names.join(', ')} ${names.length === 1 ? 'is' : 'are'} typing…`;
      return;
    }
    if (conversation.type === 'direct') {
      const other = (conversation.members || []).find(member => Number(member.id) !== Number(state.user?.id));
      const presence = other ? state.presence.get(Number(other.id)) : null;
      if (presence?.status === 'online') { subtitle.textContent = 'Online'; return; }
      if (presence?.status === 'away') { subtitle.textContent = 'Away'; return; }
      if (presence?.last_seen_at) { subtitle.textContent = `Last seen ${formatTime(presence.last_seen_at)}`; return; }
    }
    subtitle.textContent = `${conversation.type} · ${conversation.members?.length || 0} members`;
  }

  async function setTyping(typing) {
    if (!state.activeConversation?.can_post || !navigator.onLine) return;
    const now = Date.now();
    if (typing && now - state.lastTypingSentAt < 2500) return;
    if (typing) state.lastTypingSentAt = now;
    try { await api(`conversations/${state.activeConversation.id}/typing`, {method:'POST', body:{typing}}); } catch (_) {}
  }

  function startPolling() {
    clearInterval(state.pollTimer);
    state.pollTimer = setInterval(async () => {
      if (document.hidden || state.busy) return;
      try {
        await Promise.all([loadConversations(), loadCalls(), loadNotifications()]);
        renderSidebar();
        if (state.activeConversation) {
          await Promise.all([refreshMessages(), loadConversationPresence(), loadTyping()]);
        }
      } catch (_) {}
    }, 7000);
  }

  function renderSidebar() {
    const content = $('#sn-sidebar-content');
    if (!content) return;
    const query = ($('#sn-global-search')?.value || '').trim().toLowerCase();
    if (state.currentTab === 'chats' || state.currentTab === 'communities') {
      const types = state.currentTab === 'communities' ? ['group', 'community', 'channel'] : ['direct', 'group'];
      const items = state.conversations.filter(item => types.includes(item.type) && Boolean(item.archived) === state.showArchived && (!query || `${item.title} ${item.description}`.toLowerCase().includes(query)));
      const archivedCount = state.conversations.filter(item => types.includes(item.type) && item.archived).length;
      content.innerHTML = `<button type="button" class="sn-archive-toggle" aria-pressed="${state.showArchived ? 'true' : 'false'}">${state.showArchived ? 'Back to active' : `Archived${archivedCount ? ` (${archivedCount})` : ''}`}</button>${items.length ? items.map(conversationCard).join('') : `<div class="sn-empty-list">No ${state.showArchived ? 'archived' : 'active'} conversations found.</div>`}`;
      $('.sn-archive-toggle', content)?.addEventListener('click', () => { state.showArchived = !state.showArchived; renderSidebar(); });
      $$('.sn-conversation-item', content).forEach(button => button.addEventListener('click', () => openConversation(Number(button.dataset.id))));
      return;
    }
    if (state.currentTab === 'calls') {
      content.innerHTML = state.calls.length ? state.calls.map(callCard).join('') : '<div class="sn-empty-list">No calls yet.</div>';
      return;
    }
    if (state.currentTab === 'updates') {
      loadAndRenderUpdates(content);
      return;
    }
    if (state.currentTab === 'contacts') {
      loadAndRenderContacts(content, query);
    }
  }

  function conversationCard(item) {
    const active = state.activeConversation?.id === item.id ? ' is-active' : '';
    const preview = item.last_message ? (item.last_message.body || `[${item.last_message.type}]`) : item.description || 'No messages yet';
    return `<button type="button" class="sn-conversation-item${active}" data-id="${item.id}">
      <img src="${escapeHtml(safeUrl(item.avatar))}" alt="">
      <span class="sn-list-copy"><strong>${item.muted ? '<span aria-label="Muted">🔕</span> ' : ''}${escapeHtml(item.title)}</strong><small>${escapeHtml(preview)}</small></span>
      <span class="sn-list-meta"><time>${escapeHtml(item.last_message?.created_at ? formatTime(item.last_message.created_at) : '')}</time>${item.unread_count ? `<b>${item.unread_count}</b>` : ''}</span>
    </button>`;
  }

  function callCard(call) {
    const other = call.members?.find(member => member.id !== state.user.id);
    return `<div class="sn-list-row"><img src="${escapeHtml(safeUrl(other?.avatar || ''))}" alt=""><span><strong>${escapeHtml(other?.name || 'Network call')}</strong><small>${escapeHtml(call.type)} · ${escapeHtml(call.status)} · ${escapeHtml(formatTime(call.created_at))}</small></span></div>`;
  }

  async function openConversation(id) {
    try {
      state.busy = true;
      const data = await api(`conversations/${id}`);
      state.activeConversation = data.conversation;
      $('#sn-empty-state').hidden = true;
      $('#sn-chat-view').hidden = false;
      $('#sn-chat-title').textContent = state.activeConversation.title;
      $('#sn-chat-avatar').src = safeUrl(state.activeConversation.avatar);
      state.typingUsers = [];
      updateConversationSubtitle();
      const canPost = Boolean(state.activeConversation.can_post);
      $('#sn-message-input').disabled = !canPost;
      $('#sn-message-input').placeholder = canPost ? 'Write a message' : 'Only channel administrators may post';
      $('#sn-attach-button').disabled = !canPost;
      const sendButton = $('.sn-send-btn', $('#sn-composer'));
      if (sendButton) sendButton.disabled = !canPost;
      const groupCallReady = Boolean(state.capabilities.group_calls);
      const builtInDirectCall = (state.activeConversation.members?.length || 0) === 2;
      const callsAllowed = state.activeConversation.type !== 'channel' && (builtInDirectCall || groupCallReady);
      for (const button of [$('#sn-audio-call'), $('#sn-video-call')]) {
        if (!button) continue;
        button.disabled = !callsAllowed;
        button.title = state.activeConversation.type === 'channel'
          ? 'Calls are unavailable in broadcast channels.'
          : (button.disabled ? 'Group calls require an approved SFU interface.' : '');
      }
      await Promise.all([refreshMessages(true), loadConversationPresence(), loadTyping()]);
      renderSidebar();
      root.classList.add('sn-chat-open');
    } catch (error) { toast(error.message, 'error'); }
    finally { state.busy = false; }
  }

  async function refreshMessages(scroll = false) {
    if (!state.activeConversation) return;
    const data = await api(`conversations/${state.activeConversation.id}/messages?limit=100`);
    const previousLast = state.messages.at(-1)?.id || 0;
    state.messages = data.messages || [];
    renderMessages();
    const last = state.messages.at(-1)?.id || 0;
    if (last) api(`conversations/${state.activeConversation.id}/read`, {method:'POST', body:{message_id:last}}).catch(() => {});
    if (scroll || last !== previousLast) $('#sn-message-list')?.scrollTo({top: $('#sn-message-list').scrollHeight, behavior: scroll ? 'auto' : 'smooth'});
  }

  function renderMessages() {
    const list = $('#sn-message-list');
    if (!list) return;
    list.innerHTML = state.messages.length ? state.messages.map(messageBubble).join('') : '<div class="sn-empty-list">No messages yet.</div>';
    $$('.sn-message-reply', list).forEach(button => button.addEventListener('click', () => {
      state.replyTo = Number(button.dataset.id);
      const message = state.messages.find(item => item.id === state.replyTo);
      const preview = $('#sn-reply-preview');
      preview.hidden = false;
      preview.innerHTML = `<span>Replying to ${escapeHtml(message?.sender?.name || 'message')}</span><button type="button" aria-label="Cancel reply">×</button>`;
      $('button', preview).addEventListener('click', () => { state.replyTo = 0; preview.hidden = true; });
      $('#sn-message-input').focus();
    }));
    $$('.sn-message-react', list).forEach(button => button.addEventListener('click', () => reactMessage(Number(button.dataset.id), button.dataset.reaction)));
    $$('.sn-message-report', list).forEach(button => button.addEventListener('click', () => reportDialog({message_id:Number(button.dataset.id), conversation_id:state.activeConversation.id})));
  }

  function messageBubble(message) {
    const mine = message.sender?.id === state.user.id;
    const attachment = attachmentHtml(message.attachment);
    const body = message.deleted ? '<em>Message deleted</em>' : escapeHtml(message.body).replace(/\n/g, '<br>');
    const reactions = (message.reactions || []).map(item => `<span>${escapeHtml(item.reaction)} ${item.count}</span>`).join('');
    return `<article class="sn-message${mine ? ' is-mine' : ''}" data-message-id="${message.id}">
      <div class="sn-message-bubble"><small class="sn-message-author">${escapeHtml(message.sender?.name || 'Deleted account')}</small>${body ? `<div class="sn-message-body">${body}</div>` : ''}${attachment}${reactions ? `<div class="sn-reactions">${reactions}</div>` : ''}<time>${escapeHtml(formatTime(message.created_at))}${message.edited ? ' · edited' : ''}</time></div>
      <div class="sn-message-actions"><button type="button" class="sn-message-reply" data-id="${message.id}" aria-label="Reply">↩</button>${message.deleted ? '' : `<button type="button" class="sn-message-react" data-id="${message.id}" data-reaction="👍" aria-label="Like">👍</button>`}<button type="button" class="sn-message-report" data-id="${message.id}" aria-label="Report">!</button></div>
    </article>`;
  }

  function attachmentHtml(attachment) {
    if (!attachment) return '';
    if (attachment.unavailable) return `<div class="sn-attachment is-unavailable">${escapeHtml(attachment.title || 'Attachment unavailable')}</div>`;
    if (attachment.type === 'image') return `<a class="sn-attachment" href="${escapeHtml(safeUrl(attachment.url))}" target="_blank" rel="noopener"><img src="${escapeHtml(safeUrl(attachment.url))}" alt="${escapeHtml(attachment.name)}"></a>`;
    if (attachment.type === 'video') return `<video class="sn-attachment" controls preload="metadata" src="${escapeHtml(safeUrl(attachment.url))}"></video>`;
    if (attachment.type === 'audio') return `<audio class="sn-attachment" controls preload="metadata" src="${escapeHtml(safeUrl(attachment.url))}"></audio>`;
    return `<a class="sn-attachment sn-document" href="${escapeHtml(safeUrl(attachment.url))}">📄 ${escapeHtml(attachment.name)} <small>${Math.ceil((attachment.size || 0) / 1024)} KB</small></a>`;
  }

  async function reactMessage(id, reaction) {
    try { await api(`messages/${id}/reaction`, {method:'POST', body:{reaction}}); await refreshMessages(); }
    catch (error) { toast(error.message, 'error'); }
  }

  $('#sn-composer')?.addEventListener('submit', async event => {
    event.preventDefault();
    if (!state.activeConversation || !state.activeConversation.can_post || state.busy) return;
    const input = $('#sn-message-input');
    const file = $('#sn-file-input').files?.[0];
    if (!input.value.trim() && !file) return;
    const form = new FormData();
    form.set('body', input.value.trim());
    form.set('client_id', uuid());
    if (state.replyTo) form.set('reply_to', String(state.replyTo));
    if (file) form.set('attachment', file, file.name);
    try {
      state.busy = true;
      await api(`conversations/${state.activeConversation.id}/messages`, {method:'POST', body:form});
      await setTyping(false);
      input.value = '';
      $('#sn-file-input').value = '';
      state.replyTo = 0;
      $('#sn-reply-preview').hidden = true;
      await refreshMessages(true);
      await loadConversations();
      renderSidebar();
    } catch (error) { toast(error.message, 'error'); }
    finally { state.busy = false; }
  });

  $('#sn-attach-button')?.addEventListener('click', () => $('#sn-file-input').click());
  $('#sn-file-input')?.addEventListener('change', () => {
    const file = $('#sn-file-input').files?.[0];
    if (file) toast(`Ready to send: ${file.name}`);
  });

  $('#sn-message-input')?.addEventListener('input', () => {
    clearTimeout(state.typingStopTimer);
    if ($('#sn-message-input').value.trim()) setTyping(true);
    state.typingStopTimer = setTimeout(() => setTyping(false), 5000);
  });
  $('#sn-message-input')?.addEventListener('blur', () => {
    clearTimeout(state.typingStopTimer);
    setTyping(false);
  });

  $$('.sn-tab').forEach(button => button.addEventListener('click', () => {
    state.currentTab = button.dataset.tab;
    $$('.sn-tab').forEach(item => { item.classList.toggle('is-active', item === button); item.toggleAttribute('aria-current', item === button); });
    renderSidebar();
  }));
  $('#sn-global-search')?.addEventListener('input', renderSidebar);
  $('#sn-mobile-back')?.addEventListener('click', () => root.classList.remove('sn-chat-open'));

  $('#sn-new-button')?.addEventListener('click', () => {
    openModal('Create conversation', `<form id="sn-new-conversation" class="sn-form">
      <label for="sn-new-type">Type</label><select id="sn-new-type"><option value="direct">Direct message</option>${state.capabilities.create_group ? '<option value="group">Group</option>' : ''}${state.capabilities.create_community ? '<option value="community">Community</option>' : ''}${state.capabilities.create_channel ? '<option value="channel">Channel</option>' : ''}</select>
      <label for="sn-new-user">Member ID</label><input id="sn-new-user" type="number" min="1" required>
      <label for="sn-new-title">Title (groups only)</label><input id="sn-new-title" maxlength="191">
      <button class="sn-btn sn-btn-primary" type="submit">Create</button></form>`, body => {
        $('#sn-new-conversation', body).addEventListener('submit', async event => {
          event.preventDefault();
          const type = $('#sn-new-type').value;
          const userId = Number($('#sn-new-user').value);
          try {
            const payload = type === 'direct' ? {type, user_id:userId} : {type, member_ids:[userId], title:$('#sn-new-title').value};
            const data = await api('conversations', {method:'POST', body:payload});
            closeModal(); await loadConversations(); renderSidebar(); await openConversation(data.conversation.id);
          } catch (error) { toast(error.message, 'error'); }
        });
      });
    });

  async function loadAndRenderContacts(container, search = '') {
    setLoading(container);
    try {
      const data = await api(`contacts${search.length >= 3 ? `?search=${encodeURIComponent(search)}` : ''}`);
      container.innerHTML = `<section class="sn-contact-section"><h3>Contacts</h3>${(data.contacts || []).map(userCard).join('') || '<p>No accepted contacts.</p>'}</section>
        <section class="sn-contact-section"><h3>Incoming requests</h3>${(data.incoming || []).map(requestCard).join('') || '<p>No incoming requests.</p>'}</section>
        <section class="sn-contact-section"><h3>Directory</h3>${(data.directory || []).map(directoryCard).join('') || '<p>Type at least three characters to search.</p>'}</section>`;
      $$('.sn-contact-message', container).forEach(button => button.addEventListener('click', () => createDirect(Number(button.dataset.id))));
      $$('.sn-contact-decision', container).forEach(button => button.addEventListener('click', () => decideContact(Number(button.dataset.request), button.dataset.decision)));
      $$('.sn-contact-request', container).forEach(button => button.addEventListener('click', () => requestContact(Number(button.dataset.id))));
    } catch (error) { container.innerHTML = `<div class="sn-error-state">${escapeHtml(error.message)}</div>`; }
  }

  const userCard = user => `<div class="sn-list-row"><img src="${escapeHtml(safeUrl(user.avatar))}" alt=""><span><strong>${escapeHtml(user.name)}</strong><small>${escapeHtml(user.role_label || user.phone_masked || '')}</small></span><button type="button" class="sn-btn sn-contact-message" data-id="${user.id}">Message</button></div>`;
  const requestCard = item => `<div class="sn-list-row"><img src="${escapeHtml(safeUrl(item.user.avatar))}" alt=""><span><strong>${escapeHtml(item.user.name)}</strong><small>Contact request</small></span><button type="button" class="sn-btn sn-contact-decision" data-request="${item.request_id}" data-decision="accept">Accept</button><button type="button" class="sn-btn sn-btn-ghost sn-contact-decision" data-request="${item.request_id}" data-decision="decline">Decline</button></div>`;
  const directoryCard = user => `<div class="sn-list-row"><img src="${escapeHtml(safeUrl(user.avatar))}" alt=""><span><strong>${escapeHtml(user.name)}</strong><small>${escapeHtml(user.role_label || '')}</small></span><button type="button" class="sn-btn sn-contact-request" data-id="${user.id}">Connect</button></div>`;

  async function requestContact(userId) { try { await api('contacts', {method:'POST', body:{user_id:userId}}); toast('Contact request sent.', 'success'); renderSidebar(); } catch (error) { toast(error.message, 'error'); } }
  async function decideContact(id, decision) { try { await api(`contacts/${id}`, {method:'POST', body:{decision}}); toast(decision === 'accept' ? 'Request accepted.' : 'Request declined.', 'success'); renderSidebar(); } catch (error) { toast(error.message, 'error'); } }
  async function createDirect(userId) { try { const data = await api('conversations', {method:'POST', body:{type:'direct', user_id:userId}}); await loadConversations(); await openConversation(data.conversation.id); } catch (error) { toast(error.message, 'error'); } }

  async function loadAndRenderUpdates(container) {
    setLoading(container);
    try {
      const data = await api('updates');
      container.innerHTML = `<button type="button" id="sn-create-update" class="sn-btn sn-btn-primary sn-wide-btn">Create update</button>${(data.updates || []).map(updateCard).join('') || '<div class="sn-empty-list">No active updates.</div>'}`;
      $('#sn-create-update', container)?.addEventListener('click', createUpdateDialog);
      $$('.sn-update', container).forEach(item => item.addEventListener('click', () => api(`updates/${item.dataset.id}/view`, {method:'POST', body:{}}).catch(() => {})));
    } catch (error) { container.innerHTML = `<div class="sn-error-state">${escapeHtml(error.message)}</div>`; }
  }

  function updateCard(update) {
    return `<article class="sn-update" data-id="${update.id}"><header><img src="${escapeHtml(safeUrl(update.user?.avatar || ''))}" alt=""><strong>${escapeHtml(update.user?.name || '')}</strong></header><p>${escapeHtml(update.body).replace(/\n/g, '<br>')}</p>${attachmentHtml(update.media)}<small>${escapeHtml(update.privacy)} · expires ${escapeHtml(formatTime(update.expires_at))}</small></article>`;
  }

  function createUpdateDialog() {
    openModal('Create update', `<form id="sn-update-form" class="sn-form"><label for="sn-update-body">Update</label><textarea id="sn-update-body" maxlength="5000"></textarea><label for="sn-update-privacy">Visibility</label><select id="sn-update-privacy"><option value="contacts">Contacts</option><option value="private">Only me</option>${state.capabilities.publish_public_update ? '<option value="public">Public</option>' : ''}</select><label for="sn-update-file">Media</label><input id="sn-update-file" type="file"><button type="submit" class="sn-btn sn-btn-primary">Publish update</button></form>`, body => {
      $('#sn-update-form', body).addEventListener('submit', async event => {
        event.preventDefault();
        const form = new FormData(); form.set('body', $('#sn-update-body').value); form.set('privacy', $('#sn-update-privacy').value);
        const file = $('#sn-update-file').files?.[0]; if (file) form.set('attachment', file, file.name);
        try { await api('updates', {method:'POST', body:form}); closeModal(); renderSidebar(); } catch (error) { toast(error.message, 'error'); }
      });
    });
  }

  $('#sn-notifications-button')?.addEventListener('click', () => {
    openModal('Notifications', state.notifications.length ? state.notifications.map(item => `<article class="sn-notification${item.is_read ? '' : ' is-unread'}"><strong>${escapeHtml(item.title)}</strong><p>${escapeHtml(item.body || '')}</p><small>${escapeHtml(formatTime(item.created_at))}</small></article>`).join('') : '<p>No notifications.</p>');
    api('notifications/read', {method:'POST', body:{}}).then(loadNotifications).catch(() => {});
  });

  $('#sn-settings-button')?.addEventListener('click', () => {
    const fields = ['phone_visibility','last_seen','profile_photo','groups','calls','messages','updates'];
    const html = `<form id="sn-privacy-form" class="sn-form">${fields.map(key => `<label>${escapeHtml(key.replaceAll('_', ' '))}<select name="${key}">${['everyone','contacts','nobody'].map(value => `<option value="${value}"${state.privacy[key] === value ? ' selected' : ''}>${value}</option>`).join('')}</select></label>`).join('')}<button class="sn-btn sn-btn-primary" type="submit">Save privacy settings</button></form>`;
    openModal('Network privacy', html, body => $('#sn-privacy-form', body).addEventListener('submit', async event => {
      event.preventDefault(); const privacy = Object.fromEntries(new FormData(event.currentTarget));
      try { const data = await api('me', {method:'POST', body:{privacy}}); state.privacy = data.privacy; closeModal(); toast('Privacy settings saved.', 'success'); } catch (error) { toast(error.message, 'error'); }
    }));
  });

  $('#sn-profile-button')?.addEventListener('click', () => openModal('My Network profile', `<div class="sn-profile-card"><img src="${escapeHtml(safeUrl(state.user?.avatar || ''))}" alt=""><h3>${escapeHtml(state.user?.name || '')}</h3><p>${escapeHtml(state.user?.about || '')}</p><small>${escapeHtml(state.user?.phone || state.user?.phone_masked || '')}</small></div>`));

  $('#sn-chat-info')?.addEventListener('click', () => {
    if (!state.activeConversation) return;
    const conversation = state.activeConversation;
    const eligibleOwners = (conversation.members || []).filter(member => member.id !== state.user?.id);
    const transfer = conversation.type !== 'direct' && conversation.viewer_role === 'owner' && eligibleOwners.length
      ? `<form id="sn-owner-transfer-form" class="sn-form sn-owner-transfer"><label for="sn-new-owner">Transfer ownership</label><select id="sn-new-owner" required>${eligibleOwners.map(member => `<option value="${Number(member.id)}">${escapeHtml(member.name)}</option>`).join('')}</select><button type="submit" class="sn-btn">Transfer ownership</button></form>`
      : '';
    const memberList = (conversation.members || []).map(member => `${escapeHtml(member.name)}${member.conversation_role ? ` (${escapeHtml(member.conversation_role)})` : ''}`).join(', ');
    const preferences = `<div class="sn-conversation-preferences"><button type="button" id="sn-toggle-mute" class="sn-btn">${conversation.muted ? 'Unmute' : 'Mute'} conversation</button><button type="button" id="sn-toggle-archive" class="sn-btn">${conversation.archived ? 'Restore' : 'Archive'} conversation</button></div>`;
    openModal('Conversation information', `<p><strong>${escapeHtml(conversation.title)}</strong></p><p>${escapeHtml(conversation.description || '')}</p><p>${memberList}</p>${preferences}${transfer}<button type="button" id="sn-report-conversation" class="sn-btn">Report conversation</button>`, body => {
      $('#sn-report-conversation', body)?.addEventListener('click', () => reportDialog({conversation_id:conversation.id}));
      $('#sn-toggle-mute', body)?.addEventListener('click', () => updateConversationPreference('muted', !conversation.muted));
      $('#sn-toggle-archive', body)?.addEventListener('click', () => updateConversationPreference('archived', !conversation.archived));
      $('#sn-owner-transfer-form', body)?.addEventListener('submit', async event => {
        event.preventDefault();
        const userId = Number($('#sn-new-owner', body)?.value || 0);
        if (!userId || !window.confirm('Transfer conversation ownership to this member?')) return;
        try {
          const data = await api(`conversations/${conversation.id}/owner`, {method:'POST', body:{user_id:userId}});
          state.activeConversation = data.conversation;
          closeModal();
          await loadConversations();
          await openConversation(conversation.id);
          toast('Conversation ownership transferred.', 'success');
        } catch (error) { toast(error.message, 'error'); }
      });
    });
  });

  async function updateConversationPreference(key, value) {
    if (!state.activeConversation || !['muted', 'archived'].includes(key)) return;
    const conversationId = state.activeConversation.id;
    try {
      const data = await api(`conversations/${conversationId}/preferences`, {method:'POST', body:{[key]:value}});
      state.activeConversation = {...state.activeConversation, ...data.preferences};
      closeModal();
      await loadConversations();
      if (key === 'archived' && value) {
        state.activeConversation = null;
        state.messages = [];
        $('#sn-chat-view').hidden = true;
        $('#sn-empty-state').hidden = false;
        root.classList.remove('sn-chat-open');
      }
      renderSidebar();
      toast(`${key === 'muted' ? 'Notification preference' : 'Archive preference'} updated.`, 'success');
    } catch (error) { toast(error.message, 'error'); }
  }

  function reportDialog(target) {
    openModal('Report', `<form id="sn-report-form" class="sn-form"><label for="sn-report-category">Category</label><select id="sn-report-category">${['spam','fraud','harassment','threat','hate','impersonation','fake_doctor','medical_misinformation','sexual_content','child_safety','illegal_products','malware','stolen_account','privacy'].map(item => `<option value="${item}">${item.replaceAll('_',' ')}</option>`).join('')}</select><label for="sn-report-details">Details</label><textarea id="sn-report-details" maxlength="4000"></textarea><button class="sn-btn sn-btn-primary" type="submit">Submit report</button></form>`, body => $('#sn-report-form', body).addEventListener('submit', async event => {
      event.preventDefault();
      try { await api('report', {method:'POST', body:{...target, category:$('#sn-report-category').value, details:$('#sn-report-details').value}}); closeModal(); toast('Report submitted.', 'success'); } catch (error) { toast(error.message, 'error'); }
    }));
  }

  $('#sn-audio-call')?.addEventListener('click', () => startCall('audio'));
  $('#sn-video-call')?.addEventListener('click', () => startCall('video'));

  async function startCall(type) {
    if (!state.activeConversation || state.currentCall) return;
    try {
      const data = await api('calls', {method:'POST', body:{conversation_id:state.activeConversation.id, type}});
      state.currentCall = data.call;
      if (data.ice_servers) state.iceServers = data.ice_servers;
      await openCallMedia(type);
      await createPeer(true);
    } catch (error) { await endLocalCall(false); toast(error.message, 'error'); }
  }

  function showIncomingCall(call) {
    const caller = call.members?.find(member => member.id === call.initiator_id);
    openModal('Incoming Network call', `<div class="sn-incoming-call"><img src="${escapeHtml(safeUrl(caller?.avatar || ''))}" alt=""><h3>${escapeHtml(caller?.name || 'Network member')}</h3><p>${escapeHtml(call.type)} call</p><button type="button" id="sn-call-accept" class="sn-btn sn-btn-primary">Accept</button><button type="button" id="sn-call-decline" class="sn-btn">Decline</button></div>`, body => {
      $('#sn-call-accept', body).addEventListener('click', async () => {
        try {
          const joined = await api(`calls/${call.id}/status`, {method:'POST', body:{status:'joined'}});
          if (joined.ice_servers) state.iceServers = joined.ice_servers;
          closeModal();
          state.currentCall = {...call, member_status:'joined', status:'active'};
          await openCallMedia(call.type);
          await createPeer(false);
        } catch (error) { toast(error.message, 'error'); }
      });
      $('#sn-call-decline', body).addEventListener('click', async () => { try { await api(`calls/${call.id}/status`, {method:'POST', body:{status:'declined'}}); } catch (_) {} closeModal(); });
    });
  }

  async function openCallMedia(type) {
    state.localStream = await navigator.mediaDevices.getUserMedia({audio:true, video:type === 'video'});
    $('#sn-local-video').srcObject = state.localStream;
    $('#sn-call-overlay').hidden = false;
    $('#sn-call-status').textContent = 'Connecting…';
  }

  async function createPeer(initiator) {
    if (!state.currentCall) return;
    state.peer = new RTCPeerConnection({iceServers: state.iceServers || []});
    state.localStream.getTracks().forEach(track => state.peer.addTrack(track, state.localStream));
    state.peer.ontrack = event => { $('#sn-remote-video').srcObject = event.streams[0]; };
    state.peer.onicecandidate = event => { if (event.candidate) sendCallSignal('candidate', event.candidate.toJSON()).catch(() => {}); };
    state.peer.onconnectionstatechange = () => {
      const status = state.peer?.connectionState || 'closed';
      $('#sn-call-status').textContent = status;
      if (['failed','closed'].includes(status)) endLocalCall(true);
    };
    state.signalAfter = 0;
    clearInterval(state.signalTimer);
    state.signalTimer = setInterval(pollSignals, 1200);
    if (initiator) {
      const offer = await state.peer.createOffer();
      await state.peer.setLocalDescription(offer);
      await sendCallSignal('offer', offer.toJSON());
    }
  }

  function otherCallUserId() { return state.currentCall?.members?.find(member => member.id !== state.user.id)?.id || 0; }
  async function sendCallSignal(type, payload) {
    if (!state.currentCall) return;
    const to = otherCallUserId();
    if (!to) throw new Error('Call member unavailable.');
    await api(`calls/${state.currentCall.id}/signals`, {method:'POST', body:{to_user_id:to, type, payload}});
  }

  async function pollSignals() {
    if (!state.currentCall || !state.peer) return;
    try {
      const data = await api(`calls/${state.currentCall.id}/signals?after=${state.signalAfter}`);
      const ack = [];
      for (const signal of data.signals || []) {
        state.signalAfter = Math.max(state.signalAfter, signal.id); ack.push(signal.id);
        if (signal.type === 'offer') {
          await state.peer.setRemoteDescription(signal.payload);
          const answer = await state.peer.createAnswer(); await state.peer.setLocalDescription(answer); await sendCallSignal('answer', answer.toJSON());
        } else if (signal.type === 'answer') {
          await state.peer.setRemoteDescription(signal.payload);
        } else if (signal.type === 'candidate') {
          await state.peer.addIceCandidate(signal.payload);
        } else if (signal.type === 'bye') {
          await endLocalCall(false);
        }
      }
      if (ack.length && state.currentCall) await api(`calls/${state.currentCall.id}/signals/ack`, {method:'POST', body:{ids:ack}});
    } catch (_) {}
  }

  async function endLocalCall(notify = true) {
    const call = state.currentCall;
    if (call && notify) { try { await sendCallSignal('bye', {}); } catch (_) {} }
    if (call) { try { await api(`calls/${call.id}/status`, {method:'POST', body:{status:'left'}}); } catch (_) {} }
    clearInterval(state.signalTimer); state.signalTimer = null;
    state.peer?.close(); state.peer = null;
    state.localStream?.getTracks().forEach(track => track.stop()); state.localStream = null;
    $('#sn-local-video').srcObject = null; $('#sn-remote-video').srcObject = null; $('#sn-call-overlay').hidden = true;
    state.currentCall = null; state.signalAfter = 0;
    loadCalls().then(renderSidebar).catch(() => {});
  }

  $('#sn-call-end')?.addEventListener('click', () => endLocalCall(true));
  $('#sn-call-mute')?.addEventListener('click', event => { const track = state.localStream?.getAudioTracks()[0]; if (track) { track.enabled = !track.enabled; event.currentTarget.textContent = track.enabled ? 'Mute' : 'Unmute'; } });
  $('#sn-call-camera')?.addEventListener('click', event => { const track = state.localStream?.getVideoTracks()[0]; if (track) { track.enabled = !track.enabled; event.currentTarget.textContent = track.enabled ? 'Camera' : 'Camera off'; } });

  document.addEventListener('visibilitychange', () => sendPresence(document.hidden ? 'away' : 'online'));
  window.addEventListener('online', () => { toast('Back online.', 'success'); sendPresence('online'); });
  window.addEventListener('offline', () => toast(cfg.strings?.offline || 'You are offline.', 'error'));
  window.addEventListener('pagehide', () => {
    clearInterval(state.presenceTimer);
    clearTimeout(state.typingStopTimer);
  });

  bootstrap();
})();
