(() => {
    'use strict';

    const config = window.SN_MESSAGES_CONFIG || {};
    const messagesRoot = document.getElementById('sn-messages-root');
    const settingsRoot = document.getElementById('sn-communication-settings-root');
    if ((!messagesRoot && !settingsRoot) || !config.isLoggedIn) return;

    const state = {
        conversations: [],
        activeConversation: null,
        messages: [],
        filter: 'active',
        search: '',
        pollTimer: 0,
        loadingConversation: false,
        lastReceiptAfter: 0,
        modalReturnFocus: null,
    };
    const restBase = String(config.restUrl || '').replace(/\/+$/, '') + '/';
    const currentUserId = Number(config.currentUserId || 0);
    const el = id => document.getElementById(id);

    async function api(path, options = {}) {
        if (!navigator.onLine) throw new Error(config.strings?.offline || 'You appear to be offline.');
        const init = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': String(config.nonce || ''), ...(options.headers || {}) },
        };
        if (options.body instanceof FormData) init.body = options.body;
        else if (options.body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        const response = await fetch(restBase + String(path).replace(/^\/+/, ''), init);
        let payload = null;
        try { payload = await response.json(); } catch (error) { payload = null; }
        if (!response.ok) {
            const requestError = new Error(payload?.message || config.strings?.requestFailed || 'The request could not be completed.');
            requestError.status = response.status;
            requestError.code = payload?.code ? String(payload.code) : '';
            throw requestError;
        }
        return payload || {};
    }

    function deviceId() {
        const key = String(config.deviceStorageKey || 'sn_messages_device_v1');
        try {
            const existing = window.localStorage.getItem(key);
            if (existing && /^[A-Za-z0-9._:-]{8,128}$/.test(existing)) return existing;
            let value;
            if (window.crypto && typeof window.crypto.randomUUID === 'function') value = 'web:' + window.crypto.randomUUID();
            else if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
                const bytes = new Uint8Array(16);
                window.crypto.getRandomValues(bytes);
                value = 'web:' + Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
            } else value = 'web:' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2, 14);
            window.localStorage.setItem(key, value);
            return value;
        } catch (error) {
            return 'session:' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2, 14);
        }
    }

    function make(tag, className = '', text = '') {
        const item = document.createElement(tag);
        if (className) item.className = className;
        if (text !== '') item.textContent = String(text);
        return item;
    }

    function formatDate(value) {
        const date = new Date(String(value || '').replace(' ', 'T') + 'Z');
        return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(undefined, {
            hour: 'numeric', minute: '2-digit', month: 'short', day: 'numeric',
        }).format(date);
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function setStatus(message, kind = '') {
        const item = el('snm-message-status');
        if (!item) return;
        item.textContent = message || '';
        item.dataset.kind = kind;
    }

    function toast(message, kind = 'error') {
        const item = el('snm-toast');
        if (!item) return;
        item.textContent = String(message || '');
        item.dataset.kind = kind;
        item.hidden = false;
        window.clearTimeout(Number(item.dataset.timer || 0));
        item.dataset.timer = String(window.setTimeout(() => {
            item.hidden = true;
            item.textContent = '';
        }, 5000));
    }

    function conversationUrl(id) {
        try {
            const url = new URL(String(config.conversationBaseUrl || config.messagesUrl || ''), window.location.origin);
            if (/\/messages\/$/.test(url.pathname)) {
                url.pathname += String(id) + '/';
                url.search = '';
            } else url.searchParams.set('conversation', String(id));
            return url.toString();
        } catch (error) {
            return String(config.messagesUrl || '') + '?conversation=' + encodeURIComponent(String(id));
        }
    }

    async function loadConversations() {
        const payload = await api('conversations');
        state.conversations = Array.isArray(payload.conversations) ? payload.conversations : [];
        renderConversations();
    }

    function filteredConversations() {
        const needle = state.search.trim().toLocaleLowerCase();
        return state.conversations.filter(item => {
            if (state.filter === 'active' && item.archived) return false;
            if (state.filter === 'archived' && !item.archived) return false;
            if (state.filter === 'unread' && Number(item.unread_count || 0) <= 0) return false;
            if (!needle) return true;
            return [item.title, item.description, item.last_message?.body]
                .some(value => String(value || '').toLocaleLowerCase().includes(needle));
        });
    }

    function renderConversations() {
        const list = el('snm-conversation-list');
        if (!list) return;
        list.replaceChildren();
        const items = filteredConversations();
        if (!items.length) {
            list.append(make('p', 'snm-list-empty', config.strings?.empty || 'No conversations are available yet.'));
            return;
        }
        const fragment = document.createDocumentFragment();
        items.forEach(conversation => {
            const button = make('button', 'snm-conversation');
            button.type = 'button';
            button.dataset.conversationId = String(conversation.id);
            if (Number(state.activeConversation?.id) === Number(conversation.id)) {
                button.classList.add('is-active');
                button.setAttribute('aria-current', 'true');
            }
            const avatar = make('img');
            avatar.src = String(conversation.avatar || '');
            avatar.alt = '';
            avatar.loading = 'lazy';
            const copy = make('span', 'snm-conversation-copy');
            copy.append(
                make('strong', '', conversation.title || 'Conversation'),
                make('span', '', conversation.last_message?.body || conversation.last_message?.type || conversation.description || 'No messages yet')
            );
            const meta = make('span', 'snm-conversation-meta');
            meta.append(make('time', '', conversation.last_message ? formatDate(conversation.last_message.created_at) : ''));
            const unread = Number(conversation.unread_count || 0);
            if (unread > 0) {
                const badge = make('span', 'snm-unread', Math.min(unread, 99));
                badge.setAttribute('aria-label', unread + ' unread messages');
                meta.append(badge);
            }
            button.append(avatar, copy, meta);
            button.addEventListener('click', () => openConversation(Number(conversation.id), true));
            fragment.append(button);
        });
        list.append(fragment);
    }

    async function openConversation(id, pushHistory) {
        if (!id || state.loadingConversation) return;
        state.loadingConversation = true;
        setStatus('Loading conversation…');
        try {
            const [conversationPayload, messagesPayload] = await Promise.all([
                api('conversations/' + id),
                api('conversations/' + id + '/messages?limit=100'),
            ]);
            state.activeConversation = conversationPayload.conversation || null;
            state.messages = Array.isArray(messagesPayload.messages) ? messagesPayload.messages : [];
            state.lastReceiptAfter = 0;
            if (!state.activeConversation) throw new Error('The conversation is unavailable.');
            if (pushHistory) window.history.pushState({ conversationId: id }, '', conversationUrl(id));
            showChat();
            renderConversations();
            renderMessages();
            await recordReadReceipt();
            await refreshReceiptSummaries();
            startPolling();
            setStatus('');
        } catch (error) {
            setStatus(error.message || 'The conversation could not be opened.', 'error');
            toast(error.message || 'The conversation could not be opened.');
        } finally { state.loadingConversation = false; }
    }

    function showChat() {
        const conversation = state.activeConversation;
        if (!conversation) return;
        if (el('snm-empty')) el('snm-empty').hidden = true;
        if (el('snm-chat')) el('snm-chat').hidden = false;
        el('snm-chat-title').textContent = String(conversation.title || 'Conversation');
        const memberCount = Array.isArray(conversation.members) ? conversation.members.length : 0;
        el('snm-chat-subtitle').textContent = conversation.type === 'direct'
            ? 'Direct private conversation'
            : memberCount + ' members · ' + String(conversation.type || 'group');
        el('snm-chat-avatar').src = String(conversation.avatar || '');
        el('snm-chat-avatar').alt = '';
        if (el('snm-composer')) el('snm-composer').hidden = !conversation.can_post;
        document.body.classList.add('snm-chat-open');
        updatePreferenceButtons();
    }

    function renderMessages() {
        const list = el('snm-message-list');
        if (!list) return;
        list.replaceChildren();
        state.lastReceiptAfter = 0;
        if (!state.messages.length) {
            list.append(make('p', 'snm-message-empty', 'No messages yet.'));
            return;
        }
        const fragment = document.createDocumentFragment();
        state.messages.forEach(message => fragment.append(renderMessage(message)));
        list.append(fragment);
        list.scrollTop = list.scrollHeight;
    }

    function renderMessage(message) {
        const own = Number(message.sender?.id || 0) === currentUserId;
        const article = make('article', 'snm-message' + (own ? ' is-own' : ''));
        article.dataset.messageId = String(message.id || 0);
        if (!own && message.sender?.name) article.append(make('strong', 'snm-message-sender', message.sender.name));
        if (message.deleted) article.append(make('p', 'snm-message-deleted', 'Message deleted'));
        else {
            if (message.body) article.append(make('p', 'snm-message-body', message.body));
            const attachment = renderAttachment(message.attachment);
            if (attachment) article.append(attachment);
        }
        const footer = make('footer', 'snm-message-meta');
        footer.append(make('time', '', formatDate(message.created_at)));
        if (message.edited) footer.append(make('span', '', 'edited'));
        if (own) {
            const receipt = make('span', 'snm-receipt', 'Sent');
            receipt.dataset.receiptFor = String(message.id || 0);
            footer.append(receipt);
        }
        article.append(footer);
        return article;
    }

    function renderAttachment(attachment) {
        if (!attachment || attachment.unavailable || !attachment.url) return null;
        try {
            const url = new URL(String(attachment.url), window.location.origin);
            if (url.origin !== window.location.origin) return null;
            const link = make('a', 'snm-attachment');
            link.href = url.toString();
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.append(
                make('strong', '', attachment.name || 'Private attachment'),
                make('span', '', String(attachment.type || 'file') + (Number(attachment.size || 0) > 0 ? ' · ' + formatBytes(Number(attachment.size)) : ''))
            );
            return link;
        } catch (error) { return null; }
    }

    async function recordReadReceipt() {
        if (!state.activeConversation || document.visibilityState !== 'visible') return;
        const received = state.messages.filter(message => !message.deleted && Number(message.sender?.id || 0) !== currentUserId);
        if (!received.length) return;
        const latest = Math.max(...received.map(message => Number(message.id || 0)));
        if (!latest) return;
        let complete = false;
        let previousThrough = -1;
        for (let batch = 0; batch < 20; batch += 1) {
            const result = await api('conversations/' + state.activeConversation.id + '/receipts', {
                method: 'POST',
                body: { message_id: latest, state: 'read', device_id: deviceId() },
            });
            const through = Number(result.through_message_id || 0);
            const more = Boolean(result.more);
            complete = !more && through >= latest;
            if (complete || through <= previousThrough) break;
            previousThrough = through;
        }
        if (complete) {
            const conversation = state.conversations.find(item => Number(item.id) === Number(state.activeConversation.id));
            if (conversation) {
                conversation.unread_count = 0;
                renderConversations();
            }
        }
    }

    async function refreshReceiptSummaries() {
        if (!state.activeConversation) return;
        const payload = await api('conversations/' + state.activeConversation.id + '/receipts?after=' + state.lastReceiptAfter + '&limit=100');
        (Array.isArray(payload.receipts) ? payload.receipts : []).forEach(item => {
            const receipt = document.querySelector('[data-receipt-for="' + Number(item.message_id) + '"]');
            const delivered = Number(item.delivered_count || 0);
            const read = Number(item.read_count || 0);
            const recipients = Number(item.recipient_count || 0);
            if (receipt) {
                if (item.state === 'read') receipt.textContent = recipients > 1 ? 'Read by all' : 'Read';
                else if (item.state === 'delivered') receipt.textContent = recipients > 1 ? 'Delivered to all' : 'Delivered';
                else if (item.state === 'partial') receipt.textContent = read > 0 ? read + '/' + recipients + ' read' : delivered + '/' + recipients + ' delivered';
                else receipt.textContent = 'Sent';
            }
            state.lastReceiptAfter = Math.max(state.lastReceiptAfter, Number(item.message_id || 0));
        });
    }

    async function pollMessages() {
        if (!state.activeConversation || document.visibilityState !== 'visible') return;
        const last = state.messages.length ? Number(state.messages[state.messages.length - 1].id || 0) : 0;
        try {
            const payload = await api('conversations/' + state.activeConversation.id + '/messages?after=' + last + '&limit=100');
            const incoming = Array.isArray(payload.messages) ? payload.messages : [];
            if (incoming.length) {
                const known = new Set(state.messages.map(message => Number(message.id)));
                incoming.forEach(message => { if (!known.has(Number(message.id))) state.messages.push(message); });
                renderMessages();
                await recordReadReceipt();
            }
            await refreshReceiptSummaries();
        } catch (error) {
            if ([401, 403].includes(Number(error.status || 0))) stopPolling();
        }
    }

    function startPolling() {
        stopPolling();
        state.pollTimer = window.setInterval(pollMessages, 5000);
    }

    function stopPolling() {
        if (state.pollTimer) window.clearInterval(state.pollTimer);
        state.pollTimer = 0;
    }

    async function sendMessage(event) {
        event.preventDefault();
        if (!state.activeConversation) return;
        const input = el('snm-message-input');
        const fileInput = el('snm-file');
        const body = input?.value.trim() || '';
        const file = fileInput?.files?.[0] || null;
        if (!body && !file) return;
        if (file && file.size > Number(config.maxUploadMb || 25) * 1024 * 1024) {
            toast('The attachment exceeds the permitted size.');
            return;
        }
        const form = new FormData();
        form.append('body', body);
        form.append('client_id', 'web:' + deviceId() + ':' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2, 10));
        if (file) form.append('attachment', file, file.name);
        const submit = event.submitter;
        if (submit) submit.disabled = true;
        setStatus('Sending…');
        try {
            const payload = await api('conversations/' + state.activeConversation.id + '/messages', { method: 'POST', body: form });
            if (payload.message) state.messages.push(payload.message);
            if (input) input.value = '';
            if (fileInput) fileInput.value = '';
            renderMessages();
            await loadConversations();
            setStatus('');
        } catch (error) {
            setStatus(error.message || 'The message could not be sent.', 'error');
            toast(error.message || 'The message could not be sent.');
        } finally { if (submit) submit.disabled = false; }
    }

    async function updatePreference(key, value) {
        if (!state.activeConversation) return;
        const payload = await api('conversations/' + state.activeConversation.id + '/preferences', {
            method: 'POST', body: { [key]: value },
        });
        if (!payload.preferences) return;
        state.activeConversation.muted = Boolean(payload.preferences.muted);
        state.activeConversation.archived = Boolean(payload.preferences.archived);
        const item = state.conversations.find(conversation => Number(conversation.id) === Number(state.activeConversation.id));
        if (item) {
            item.muted = state.activeConversation.muted;
            item.archived = state.activeConversation.archived;
        }
        updatePreferenceButtons();
        renderConversations();
    }

    function updatePreferenceButtons() {
        if (!state.activeConversation) return;
        const mute = el('snm-mute');
        const archive = el('snm-archive');
        if (mute) {
            mute.textContent = state.activeConversation.muted ? '🔔' : '🔕';
            mute.setAttribute('aria-label', state.activeConversation.muted ? 'Unmute conversation' : 'Mute conversation');
        }
        if (archive) {
            archive.textContent = state.activeConversation.archived ? '↥' : '▣';
            archive.setAttribute('aria-label', state.activeConversation.archived ? 'Restore conversation' : 'Archive conversation');
        }
    }

    async function openNewConversationModal() {
        const modal = el('snm-modal');
        const body = el('snm-modal-body');
        if (!modal || !body) return;
        state.modalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        modal.hidden = false;
        const closeButton = modal.querySelector('[data-snm-close]');
        if (closeButton instanceof HTMLElement) closeButton.focus();
        body.replaceChildren(make('p', '', 'Loading approved contacts…'));
        try {
            const payload = await api('contacts');
            const contacts = Array.isArray(payload.contacts) ? payload.contacts : [];
            body.replaceChildren();
            if (!contacts.length) {
                const link = make('a', 'snm-button', 'Open Network');
                link.href = String(config.networkUrl || '#');
                body.append(make('p', '', 'No accepted contacts are available. Open Network to create an approved contact first.'), link);
                return;
            }
            contacts.forEach(contact => {
                const button = make('button', 'snm-contact-option');
                button.type = 'button';
                const avatar = make('img');
                avatar.src = String(contact.avatar || '');
                avatar.alt = '';
                button.append(avatar, make('span', '', contact.name || 'Network member'));
                button.addEventListener('click', async () => {
                    button.disabled = true;
                    try {
                        const created = await api('conversations', { method: 'POST', body: { type: 'direct', user_id: Number(contact.id) } });
                        closeModal();
                        await loadConversations();
                        if (created.conversation) await openConversation(Number(created.conversation.id), true);
                    } catch (error) { toast(error.message || 'The conversation could not be created.'); }
                    finally { button.disabled = false; }
                });
                body.append(button);
            });
        } catch (error) {
            body.replaceChildren(make('p', '', error.message || 'Contacts could not be loaded.'));
        }
    }

    function closeModal() {
        const modal = el('snm-modal');
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        if (state.modalReturnFocus instanceof HTMLElement && document.contains(state.modalReturnFocus)) state.modalReturnFocus.focus();
        state.modalReturnFocus = null;
    }

    function handleModalKeydown(event) {
        const modal = el('snm-modal');
        if (!modal || modal.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(modal.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'))
            .filter(item => item instanceof HTMLElement && !item.hidden && item.offsetParent !== null);
        if (!focusable.length) {
            event.preventDefault();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function bindMessagesEvents() {
        el('snm-conversation-search')?.addEventListener('input', event => {
            state.search = event.currentTarget.value;
            renderConversations();
        });
        document.querySelectorAll('.snm-filter').forEach(button => button.addEventListener('click', () => {
            state.filter = String(button.dataset.filter || 'active');
            document.querySelectorAll('.snm-filter').forEach(item => {
                const active = item === button;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            renderConversations();
        }));
        el('snm-composer')?.addEventListener('submit', sendMessage);
        el('snm-attach')?.addEventListener('click', () => el('snm-file')?.click());
        el('snm-mute')?.addEventListener('click', () => updatePreference('muted', !Boolean(state.activeConversation?.muted)).catch(error => toast(error.message)));
        el('snm-archive')?.addEventListener('click', () => updatePreference('archived', !Boolean(state.activeConversation?.archived)).catch(error => toast(error.message)));
        el('snm-new-conversation')?.addEventListener('click', openNewConversationModal);
        document.querySelectorAll('[data-snm-close]').forEach(item => item.addEventListener('click', closeModal));
        document.addEventListener('keydown', handleModalKeydown);
        el('snm-back')?.addEventListener('click', () => document.body.classList.remove('snm-chat-open'));
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                recordReadReceipt().catch(() => undefined);
                pollMessages();
            }
        });
        window.addEventListener('popstate', () => window.location.reload());
        window.addEventListener('beforeunload', stopPolling);
    }

    async function initMessages() {
        bindMessagesEvents();
        try {
            await loadConversations();
            const requested = Number(config.conversationId || 0);
            if (requested > 0) await openConversation(requested, false);
        } catch (error) {
            setStatus(error.message || 'Messages could not be loaded.', 'error');
            toast(error.message || 'Messages could not be loaded.');
        }
    }

    async function initSettings() {
        const form = el('snm-settings-form');
        const message = el('snm-settings-message');
        if (!form || !message) return;
        const applyPrivacy = privacy => Array.from(form.elements).forEach(control => {
            if (control instanceof HTMLSelectElement && Object.prototype.hasOwnProperty.call(privacy, control.name)) control.value = String(privacy[control.name]);
        });
        try {
            const payload = await api('me');
            applyPrivacy(payload.privacy || {});
            message.textContent = 'Current server-authoritative settings loaded.';
        } catch (error) {
            message.textContent = error.message || 'Communication settings could not be loaded.';
            message.dataset.kind = 'error';
        }
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const privacy = {};
            Array.from(form.elements).forEach(control => {
                if (control instanceof HTMLSelectElement && control.name) privacy[control.name] = control.value;
            });
            const submit = event.submitter;
            if (submit) submit.disabled = true;
            message.textContent = 'Saving…';
            message.dataset.kind = '';
            try {
                const payload = await api('me', { method: 'POST', body: { privacy } });
                applyPrivacy(payload.privacy || {});
                message.textContent = 'Communication settings saved.';
                message.dataset.kind = 'success';
            } catch (error) {
                message.textContent = error.message || 'Communication settings could not be saved.';
                message.dataset.kind = 'error';
            } finally { if (submit) submit.disabled = false; }
        });
    }

    if (messagesRoot) initMessages();
    else if (settingsRoot) initSettings();
})();
