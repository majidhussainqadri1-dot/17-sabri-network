(() => {
  'use strict';

  const cfg = window.snMeetConfig || {};
  const root = document.getElementById('sn-meet-root');
  if (!root || !cfg.loggedIn) return;

  const $ = (id) => document.getElementById(id);
  const status = $('sn-meet-status');
  const alertBox = $('sn-meet-alert');
  const dashboard = $('sn-meet-dashboard');
  const room = $('sn-meet-room');
  const state = {
    meeting: null,
    participant: null,
    participants: [],
    sessionId: '',
    joined: false,
    media: null,
    localStream: null,
    screenStream: null,
    heartbeatTimer: 0,
    participantTimer: 0,
    provider: null,
    handRaised: false,
    abort: new AbortController(),
  };

  const announce = (message) => {
    if (status) status.textContent = message;
  };

  const showError = (message) => {
    if (!alertBox) return;
    alertBox.textContent = message || 'Sabri Meet could not complete this request.';
    alertBox.hidden = false;
  };

  const clearError = () => {
    if (!alertBox) return;
    alertBox.textContent = '';
    alertBox.hidden = true;
  };

  const api = async (path, options = {}) => {
    const response = await fetch(`${cfg.restRoot}${path.replace(/^\//, '')}`, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || '',
        ...(options.headers || {}),
      },
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: state.abort.signal,
    });
    let data = {};
    try { data = await response.json(); } catch (_) { data = {}; }
    if (!response.ok) {
      const error = new Error(data.message || `Request failed (${response.status}).`);
      error.code = data.code || 'request_failed';
      error.status = response.status;
      error.data = data.data || {};
      throw error;
    }
    return data;
  };

  const escapeText = (value) => String(value ?? '');
  const formatDate = (value) => {
    if (!value) return 'Not scheduled';
    const normalized = String(value).includes('T') ? String(value) : `${value.replace(' ', 'T')}Z`;
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? 'Date unavailable' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  };

  const randomSessionId = () => {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return `${window.crypto.randomUUID()}:${window.crypto.randomUUID()}`;
    }
    const bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
  };

  const sessionId = () => {
    const key = `sn-meet-session:${cfg.meetingId}`;
    let value = sessionStorage.getItem(key) || '';
    if (!/^[A-Za-z0-9._:-]{32,128}$/.test(value)) {
      value = randomSessionId();
      sessionStorage.setItem(key, value);
    }
    return value;
  };

  const dashboardInit = async () => {
    dashboard.hidden = false;
    room.hidden = true;
    announce('Loading your meetings…');
    const list = $('sn-meet-list');
    const form = $('sn-meet-create-form');
    $('sn-meet-new')?.addEventListener('click', () => {
      form.hidden = false;
      $('sn-meet-create-title')?.focus();
    });
    $('sn-meet-create-cancel')?.addEventListener('click', () => {
      form.reset();
      form.hidden = true;
      $('sn-meet-new')?.focus();
    });
    form?.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearError();
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      announce('Creating Sabri Meet…');
      try {
        const startInput = $('sn-meet-create-start')?.value || '';
        const body = {
          title: $('sn-meet-create-title')?.value.trim() || '',
          description: $('sn-meet-create-description')?.value.trim() || '',
          participant_limit: Number($('sn-meet-create-limit')?.value || 100),
          lobby_enabled: Boolean($('sn-meet-create-lobby')?.checked),
          idempotency_key: randomSessionId(),
        };
        if (startInput) body.scheduled_start = new Date(startInput).toISOString().replace('.000Z', 'Z');
        const result = await api('meetings', { method: 'POST', body });
        window.location.assign(result.meeting.url);
      } catch (error) {
        showError(error.message);
        announce('Meeting creation failed.');
        submit.disabled = false;
      }
    });

    try {
      const data = await api('meetings');
      const meetings = Array.isArray(data.meetings) ? data.meetings : [];
      list.replaceChildren();
      if (!meetings.length) {
        const empty = document.createElement('p');
        empty.textContent = 'No meetings yet. Create a private Sabri Meet session when needed.';
        list.append(empty);
      }
      meetings.forEach((meeting) => {
        const card = document.createElement('article');
        card.className = 'sn-meet-card';
        const heading = document.createElement('h3');
        heading.textContent = escapeText(meeting.title);
        const meta = document.createElement('p');
        meta.className = 'sn-meet-list-meta';
        meta.textContent = `${escapeText(meeting.status)} · ${formatDate(meeting.scheduled_start)}`;
        const open = document.createElement('a');
        open.className = 'sn-meet-primary';
        open.href = meeting.url;
        open.textContent = meeting.status === 'ended' ? 'View meeting record' : 'Open meeting';
        card.append(heading, meta, open);
        list.append(card);
      });
      announce('Meetings loaded.');
    } catch (error) {
      showError(error.message);
      announce('Meeting list unavailable.');
    }
  };

  const roomInit = async () => {
    dashboard.hidden = true;
    room.hidden = false;
    state.sessionId = sessionId();
    announce('Loading meeting…');
    try {
      const data = await api(`meetings/${encodeURIComponent(cfg.meetingId)}`);
      state.meeting = data.meeting;
      state.participant = data.participant || null;
      renderMeeting();
      await refreshParticipants();
      announce('Meeting details loaded.');
    } catch (error) {
      showError(error.message);
      announce('Meeting unavailable.');
      $('sn-meet-prejoin').hidden = true;
    }

    $('sn-meet-copy-link')?.addEventListener('click', copyLink);
    $('sn-meet-join')?.addEventListener('click', joinMeeting);
    $('sn-meet-leave')?.addEventListener('click', leaveMeeting);
    $('sn-meet-mic')?.addEventListener('click', toggleMic);
    $('sn-meet-camera')?.addEventListener('click', toggleCamera);
    $('sn-meet-share')?.addEventListener('click', toggleScreen);
    $('sn-meet-captions')?.addEventListener('click', toggleCaptions);
    $('sn-meet-hand')?.addEventListener('click', toggleHand);
    $('sn-meet-invite-form')?.addEventListener('submit', inviteMember);
    $('sn-meet-lock')?.addEventListener('click', toggleLock);
    $('sn-meet-end')?.addEventListener('click', endMeeting);
  };

  const renderMeeting = () => {
    const meeting = state.meeting;
    if (!meeting) return;
    $('sn-meet-title-room').textContent = escapeText(meeting.title);
    $('sn-meet-room-meta').textContent = `${escapeText(meeting.status)} · ${formatDate(meeting.scheduled_start)}`;
    $('sn-meet-lock').hidden = !meeting.can_end;
    $('sn-meet-end').hidden = !meeting.can_end;
    $('sn-meet-invite-form').hidden = !meeting.can_moderate;
    const chat = $('sn-meet-chat');
    if (chat) {
      chat.hidden = !meeting.chat_url;
      if (meeting.chat_url) chat.href = meeting.chat_url;
    }
    $('sn-meet-lock').textContent = meeting.locked ? 'Unlock meeting' : 'Lock meeting';
    if (meeting.status === 'ended' || meeting.status === 'cancelled') {
      $('sn-meet-prejoin').hidden = true;
      $('sn-meet-waiting').hidden = true;
      disableControls();
      announce(cfg.strings?.ended || 'This meeting has ended.');
    }
    if (state.participant?.state === 'waiting') {
      $('sn-meet-prejoin').hidden = true;
      $('sn-meet-waiting').hidden = false;
      startPolling();
    }
  };

  const joinMeeting = async () => {
    clearError();
    const button = $('sn-meet-join');
    button.disabled = true;
    announce('Requesting meeting access…');
    try {
      const data = await api(`meetings/${encodeURIComponent(cfg.meetingId)}/join`, {
        method: 'POST',
        body: { session_id: state.sessionId },
      });
      state.meeting = data.meeting;
      state.participant = data.participant;
      state.media = data.media || null;
      $('sn-meet-prejoin').hidden = true;
      if (data.session_state === 'waiting') {
        $('sn-meet-waiting').hidden = false;
        announce(cfg.strings?.waiting || 'Waiting for host admission.');
      } else {
        $('sn-meet-waiting').hidden = true;
        await enterConference();
      }
      renderMeeting();
      startPolling();
    } catch (error) {
      showError(error.message);
      announce('Could not join the meeting.');
      button.disabled = false;
    }
  };

  const enterConference = async () => {
    state.joined = true;
    $('sn-meet-leave').disabled = false;
    $('sn-meet-mic').disabled = false;
    $('sn-meet-camera').disabled = false;
    const features = state.media?.features || {};
    $('sn-meet-share').disabled = !(state.media?.available && features.screen_share);
    $('sn-meet-captions').disabled = !(state.media?.available && features.captions);
    $('sn-meet-hand').disabled = false;
    if (state.media?.available && window.SabriMeetProvider && typeof window.SabriMeetProvider.connect === 'function') {
      try {
        state.provider = await window.SabriMeetProvider.connect({
          config: state.media,
          meeting: state.meeting,
          mount: $('sn-meet-provider-stage'),
          onStatus: announce,
        });
        announce('Connected to Sabri Meet conference media.');
      } catch (error) {
        showError('The approved conference provider could not connect. Meeting controls remain available.');
        announce('Conference media connection failed.');
      }
    } else {
      $('sn-meet-provider-stage').textContent = cfg.strings?.providerUnavailable || 'Conference media is not configured.';
      announce('Joined meeting control plane; conference media provider is unavailable.');
    }
    startHeartbeat();
  };

  const refreshMeeting = async () => {
    const data = await api(`meetings/${encodeURIComponent(cfg.meetingId)}`);
    const previousState = state.participant?.state || '';
    state.meeting = data.meeting;
    state.participant = data.participant || state.participant;
    renderMeeting();
    if (previousState === 'waiting' && ['admitted', 'joined'].includes(state.participant?.state)) {
      const joined = await api(`meetings/${encodeURIComponent(cfg.meetingId)}/join`, {
        method: 'POST',
        body: { session_id: state.sessionId },
      });
      state.meeting = joined.meeting;
      state.participant = joined.participant;
      state.media = joined.media;
      $('sn-meet-waiting').hidden = true;
      await enterConference();
    }
    if (['denied', 'removed'].includes(state.participant?.state)) {
      showError('The host has not admitted this account to the meeting.');
      disableControls();
      stopTimers();
    }
  };

  const refreshParticipants = async () => {
    if (!state.meeting || !state.participant || ['denied', 'removed'].includes(state.participant.state)) return;
    try {
      const data = await api(`meetings/${encodeURIComponent(cfg.meetingId)}/participants`);
      state.participants = Array.isArray(data.participants) ? data.participants : [];
      renderParticipants();
    } catch (error) {
      if (error.status !== 404) showError(error.message);
    }
  };

  const renderParticipants = () => {
    const list = $('sn-meet-participants');
    list.replaceChildren();
    state.participants.forEach((participant) => {
      const item = document.createElement('li');
      const identity = document.createElement('span');
      const mediaLabels = [];
      if (participant.media?.hand) mediaLabels.push('hand raised');
      if (participant.media?.screen) mediaLabels.push('presenting');
      if (participant.media?.mic) mediaLabels.push('microphone on');
      if (participant.media?.camera) mediaLabels.push('camera on');
      identity.textContent = `${escapeText(participant.user?.name || 'Member')} · ${escapeText(participant.role)} · ${escapeText(participant.state)}${mediaLabels.length ? ` · ${mediaLabels.join(', ')}` : ''}`;
      item.append(identity);
      if (state.meeting?.can_moderate && participant.user?.id !== state.participant?.user?.id) {
        const actions = document.createElement('span');
        actions.className = 'sn-meet-participant-actions';
        if (participant.state === 'waiting') actions.append(actionButton('Admit', 'admit', participant.user.id));
        if (['waiting', 'invited'].includes(participant.state)) actions.append(actionButton('Deny', 'deny', participant.user.id));
        if (['admitted', 'joined'].includes(participant.state)) {
          actions.append(actionButton('Mute', 'mute', participant.user.id));
          if (participant.media?.hand) actions.append(actionButton('Lower hand', 'lower_hand', participant.user.id));
          actions.append(actionButton('Remove', 'remove', participant.user.id));
        }
        if (state.meeting.can_end && participant.role === 'participant') actions.append(actionButton('Make co-host', 'promote', participant.user.id));
        if (state.meeting.can_end && participant.role === 'cohost') actions.append(actionButton('Remove co-host', 'demote', participant.user.id));
        item.append(actions);
      }
      list.append(item);
    });
  };

  const inviteMember = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const input = $('sn-meet-invite-user');
    const userId = Number(input?.value || 0);
    if (!Number.isInteger(userId) || userId <= 0) {
      showError('Enter a valid platform user ID.');
      input?.focus();
      return;
    }
    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;
    clearError();
    try {
      const result = await api(`meetings/${encodeURIComponent(cfg.meetingId)}/invite`, {
        method: 'POST',
        body: { user_id: userId },
      });
      if (result.failed > 0) {
        showError('The invitation could not be saved. Try again or contact an administrator.');
      } else if (result.invited > 0) {
        announce('Meeting invitation sent.');
        form.reset();
      } else {
        showError('This member is not eligible or is already invited.');
      }
      await refreshParticipants();
    } catch (error) {
      showError(error.message);
    } finally {
      submit.disabled = false;
    }
  };

  const actionButton = (label, action, userId) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.addEventListener('click', () => moderate(action, userId, button));
    return button;
  };

  const moderate = async (action, userId, button) => {
    button.disabled = true;
    clearError();
    try {
      const data = await api(`meetings/${encodeURIComponent(cfg.meetingId)}/moderate`, {
        method: 'POST',
        body: { action, user_id: userId },
      });
      state.meeting = data.meeting;
      renderMeeting();
      await refreshParticipants();
      announce(`Meeting action completed: ${action}.`);
    } catch (error) {
      showError(error.message);
      button.disabled = false;
    }
  };

  const toggleLock = async () => {
    const action = state.meeting?.locked ? 'unlock' : 'lock';
    await moderate(action, 0, $('sn-meet-lock'));
  };

  const endMeeting = async () => {
    if (!window.confirm('End this Sabri Meet session for everyone?')) return;
    await moderate('end', 0, $('sn-meet-end'));
    disableControls();
    stopTimers();
    await disconnectMedia();
  };

  const startPolling = () => {
    if (!state.participantTimer) {
      state.participantTimer = window.setInterval(async () => {
        try {
          await refreshMeeting();
          await refreshParticipants();
        } catch (error) {
          if (error.status === 410 || error.status === 404) {
            stopTimers();
            disableControls();
          }
        }
      }, 5000);
    }
  };

  const startHeartbeat = () => {
    if (state.heartbeatTimer) return;
    const send = async () => {
      if (!state.joined) return;
      const media = {
        mic: Boolean(state.localStream?.getAudioTracks().some((track) => track.enabled)),
        camera: Boolean(state.localStream?.getVideoTracks().some((track) => track.enabled)),
        screen: Boolean(state.screenStream?.getVideoTracks().some((track) => track.readyState === 'live')),
        hand: state.handRaised,
      };
      try {
        await api(`meetings/${encodeURIComponent(cfg.meetingId)}/heartbeat`, {
          method: 'POST',
          body: { session_id: state.sessionId, media },
        });
      } catch (error) {
        if (error.status === 403 || error.status === 404) {
          state.joined = false;
          disableControls();
          showError(error.message);
          stopTimers();
        }
      }
    };
    send();
    state.heartbeatTimer = window.setInterval(send, 20000);
  };

  const ensureLocalStream = async (kind) => {
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
      throw new Error('Camera and microphone require HTTPS and a compatible browser.');
    }
    const needAudio = kind === 'audio' && !state.localStream?.getAudioTracks().length;
    const needVideo = kind === 'video' && !state.localStream?.getVideoTracks().length;
    if (!needAudio && !needVideo) return state.localStream;
    const added = await navigator.mediaDevices.getUserMedia({ audio: needAudio, video: needVideo });
    if (!state.localStream) state.localStream = new MediaStream();
    added.getTracks().forEach((track) => state.localStream.addTrack(track));
    $('sn-meet-local-video').srcObject = state.localStream;
    if (state.provider && typeof state.provider.setLocalStream === 'function') {
      await state.provider.setLocalStream(state.localStream);
    }
    return state.localStream;
  };

  const toggleMic = async () => {
    const button = $('sn-meet-mic');
    try {
      const hadTrack = Boolean(state.localStream?.getAudioTracks().length);
      await ensureLocalStream('audio');
      const tracks = state.localStream.getAudioTracks();
      const enabled = hadTrack ? !tracks.some((track) => track.enabled) : true;
      tracks.forEach((track) => { track.enabled = enabled; });
      button.setAttribute('aria-pressed', String(enabled));
      $('sn-meet-local-state').textContent = enabled ? 'microphone on' : 'microphone off';
      if (state.provider?.setMicrophone) await state.provider.setMicrophone(enabled);
    } catch (error) { showError(error.message); }
  };

  const toggleCamera = async () => {
    const button = $('sn-meet-camera');
    try {
      const hadTrack = Boolean(state.localStream?.getVideoTracks().length);
      await ensureLocalStream('video');
      const tracks = state.localStream.getVideoTracks();
      const enabled = hadTrack ? !tracks.some((track) => track.enabled) : true;
      tracks.forEach((track) => { track.enabled = enabled; });
      button.setAttribute('aria-pressed', String(enabled));
      if (state.provider?.setCamera) await state.provider.setCamera(enabled);
    } catch (error) { showError(error.message); }
  };

  const toggleScreen = async () => {
    const button = $('sn-meet-share');
    if (state.screenStream) {
      state.screenStream.getTracks().forEach((track) => track.stop());
      state.screenStream = null;
      button.setAttribute('aria-pressed', 'false');
      if (state.provider?.setScreenStream) await state.provider.setScreenStream(null);
      return;
    }
    if (!navigator.mediaDevices?.getDisplayMedia) {
      showError('Screen sharing is not supported by this browser.');
      return;
    }
    try {
      state.screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
      button.setAttribute('aria-pressed', 'true');
      state.screenStream.getVideoTracks()[0]?.addEventListener('ended', () => {
        state.screenStream = null;
        button.setAttribute('aria-pressed', 'false');
      }, { once: true });
      if (state.provider?.setScreenStream) await state.provider.setScreenStream(state.screenStream);
    } catch (error) {
      if (error.name !== 'NotAllowedError') showError(error.message);
    }
  };

  const toggleCaptions = async () => {
    const button = $('sn-meet-captions');
    if (!state.provider?.setCaptions) return;
    const enabled = button.getAttribute('aria-pressed') !== 'true';
    await state.provider.setCaptions(enabled);
    button.setAttribute('aria-pressed', String(enabled));
  };

  const toggleHand = async () => {
    state.handRaised = !state.handRaised;
    const button = $('sn-meet-hand');
    button.setAttribute('aria-pressed', String(state.handRaised));
    button.textContent = state.handRaised ? 'Lower hand' : 'Raise hand';
    announce(state.handRaised ? 'Your hand is raised.' : 'Your hand is lowered.');
    if (state.joined) {
      try {
        await api(`meetings/${encodeURIComponent(cfg.meetingId)}/heartbeat`, {
          method: 'POST',
          body: {
            session_id: state.sessionId,
            media: {
              mic: Boolean(state.localStream?.getAudioTracks().some((track) => track.enabled)),
              camera: Boolean(state.localStream?.getVideoTracks().some((track) => track.enabled)),
              screen: Boolean(state.screenStream?.getVideoTracks().some((track) => track.readyState === 'live')),
              hand: state.handRaised,
            },
          },
        });
        await refreshParticipants();
      } catch (error) {
        state.handRaised = !state.handRaised;
        button.setAttribute('aria-pressed', String(state.handRaised));
        button.textContent = state.handRaised ? 'Lower hand' : 'Raise hand';
        showError(error.message);
      }
    }
  };

  const leaveMeeting = async () => {
    clearError();
    $('sn-meet-leave').disabled = true;
    try {
      await api(`meetings/${encodeURIComponent(cfg.meetingId)}/leave`, {
        method: 'POST',
        body: { session_id: state.sessionId },
      });
    } catch (error) {
      showError(error.message);
    }
    state.joined = false;
    stopTimers();
    await disconnectMedia();
    disableControls();
    announce('You left the meeting.');
  };

  const disconnectMedia = async () => {
    if (state.provider?.disconnect) {
      try { await state.provider.disconnect(); } catch (_) { /* Provider cleanup is best-effort. */ }
    }
    state.provider = null;
    state.localStream?.getTracks().forEach((track) => track.stop());
    state.screenStream?.getTracks().forEach((track) => track.stop());
    state.localStream = null;
    state.screenStream = null;
    state.handRaised = false;
    const handButton = $('sn-meet-hand');
    if (handButton) { handButton.setAttribute('aria-pressed', 'false'); handButton.textContent = 'Raise hand'; }
    if ($('sn-meet-local-video')) $('sn-meet-local-video').srcObject = null;
  };

  const disableControls = () => {
    ['sn-meet-mic', 'sn-meet-camera', 'sn-meet-share', 'sn-meet-captions', 'sn-meet-hand', 'sn-meet-leave'].forEach((id) => {
      const button = $(id);
      if (button) button.disabled = true;
    });
  };

  const stopTimers = () => {
    if (state.heartbeatTimer) window.clearInterval(state.heartbeatTimer);
    if (state.participantTimer) window.clearInterval(state.participantTimer);
    state.heartbeatTimer = 0;
    state.participantTimer = 0;
  };

  const copyLink = async () => {
    try {
      await navigator.clipboard.writeText(window.location.href);
      announce('Meeting link copied.');
    } catch (_) {
      showError('The browser could not copy the meeting link.');
    }
  };

  window.addEventListener('pagehide', () => {
    stopTimers();
    state.abort.abort();
    state.localStream?.getTracks().forEach((track) => track.stop());
    state.screenStream?.getTracks().forEach((track) => track.stop());
  }, { once: true });

  if (cfg.meetingId) roomInit(); else dashboardInit();
})();
