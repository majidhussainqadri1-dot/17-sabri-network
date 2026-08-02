(() => {
    'use strict';
    const config = window.SNMessageSearch || {};
    const root = document.getElementById('sn-messages-root');
    if (!root || root.dataset.authenticated !== '1' || !config.root || !config.nonce) return;

    const state = { open: false, conversationId: 0, cursor: '', queryKey: '', busy: false };
    const el = id => document.getElementById(id);
    const api = async path => {
        const response = await fetch(String(config.root) + path, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': String(config.nonce), 'Accept': 'application/json' }
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Message search is unavailable.');
        return data;
    };
    const conversationId = () => {
        const match = window.location.pathname.match(/\/messages(?:-safe)?\/([1-9]\d*)\/?$/);
        if (match) return Number(match[1]);
        return Number(new URL(window.location.href).searchParams.get('conversation') || 0);
    };
    const text = (tag, value, className = '') => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        node.textContent = String(value ?? '');
        return node;
    };

    const panel = document.createElement('section');
    panel.id = 'snm-message-search-panel';
    panel.className = 'snms-panel';
    panel.hidden = true;
    panel.setAttribute('aria-labelledby', 'snms-title');
    panel.innerHTML = '<header class="snms-header"><h3 id="snms-title">Search messages</h3><button type="button" class="snm-icon-button" data-snms-close aria-label="Close message search">×</button></header><form id="snms-form" class="snms-form"><label for="snms-query">Words in this conversation</label><div class="snms-query-row"><input id="snms-query" type="search" minlength="2" maxlength="160" required autocomplete="off"><button type="submit" class="snm-button snm-button-primary">Search</button></div><div class="snms-filters"><label>Type<select id="snms-type"><option value="">All</option><option value="text">Text</option><option value="image">Image</option><option value="video">Video</option><option value="audio">Audio</option><option value="document">Document</option></select></label><label>From<input id="snms-from" type="date"></label><label>To<input id="snms-to" type="date"></label></div></form><div id="snms-status" class="snms-status" role="status" aria-live="polite"></div><div id="snms-results" class="snms-results"></div><button id="snms-more" type="button" class="snm-button" hidden>Load more</button><div id="snms-context" class="snms-context" hidden></div>';

    const chat = el('snm-chat');
    const list = el('snm-message-list');
    const actions = document.querySelector('.snm-chat-actions');
    if (!chat || !list || !actions) return;
    list.before(panel);
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'snm-icon-button';
    trigger.setAttribute('aria-label', 'Search messages in this conversation');
    trigger.setAttribute('aria-controls', panel.id);
    trigger.textContent = '⌕';
    actions.prepend(trigger);

    const status = message => { el('snms-status').textContent = String(message || ''); };
    const clear = () => {
        el('snms-results').replaceChildren();
        el('snms-context').replaceChildren();
        el('snms-context').hidden = true;
        el('snms-more').hidden = true;
        state.cursor = '';
    };
    const close = () => {
        panel.hidden = true;
        state.open = false;
        trigger.setAttribute('aria-expanded', 'false');
        trigger.focus();
    };
    const open = () => {
        state.conversationId = conversationId();
        if (!state.conversationId) { status('Select a conversation before searching.'); return; }
        panel.hidden = false;
        state.open = true;
        trigger.setAttribute('aria-expanded', 'true');
        el('snms-query').focus();
    };

    const queryParams = includeCursor => {
        const params = new URLSearchParams();
        params.set('q', el('snms-query').value.trim());
        const type = el('snms-type').value;
        const from = el('snms-from').value;
        const to = el('snms-to').value;
        if (type) params.set('type', type);
        if (from) params.set('from', from);
        if (to) params.set('to', to);
        params.set('limit', '25');
        if (includeCursor && state.cursor) params.set('cursor', state.cursor);
        return params;
    };
    const renderResult = item => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'snms-result';
        button.dataset.contextCursor = String(item.context_cursor || '');
        const head = document.createElement('span');
        head.className = 'snms-result-head';
        head.append(text('strong', item.sender?.name || 'Unavailable account'));
        head.append(text('time', item.created_at || ''));
        const body = text('span', item.body || '[' + String(item.message_type || 'message') + ']', 'snms-result-body');
        button.append(head, body);
        button.addEventListener('click', () => loadContext(button.dataset.contextCursor, Number(item.id || 0)));
        return button;
    };
    const load = async append => {
        if (state.busy) return;
        state.conversationId = conversationId();
        const q = el('snms-query').value.trim();
        if (q.length < 2) { status('Enter at least two characters.'); return; }
        const key = queryParams(false).toString();
        if (!append || state.queryKey !== key) { clear(); state.queryKey = key; }
        state.busy = true;
        status('Searching…');
        try {
            const data = await api('conversations/' + state.conversationId + '/search?' + queryParams(append).toString());
            const results = Array.isArray(data.results) ? data.results : [];
            const fragment = document.createDocumentFragment();
            results.forEach(item => fragment.append(renderResult(item)));
            el('snms-results').append(fragment);
            state.cursor = String(data.next_cursor || '');
            el('snms-more').hidden = !state.cursor;
            status(results.length ? 'Search results loaded.' : (append ? 'No more results.' : 'No matching messages.'));
        } catch (error) {
            status(error.message);
        } finally { state.busy = false; }
    };
    const loadContext = async (cursor, targetId) => {
        if (!cursor || state.busy) return;
        state.busy = true;
        status('Loading message context…');
        try {
            const data = await api('conversations/' + state.conversationId + '/search/context?cursor=' + encodeURIComponent(cursor));
            const box = el('snms-context');
            box.replaceChildren(text('h4', 'Message context'));
            (Array.isArray(data.messages) ? data.messages : []).forEach(item => {
                const article = document.createElement('article');
                article.className = 'snms-context-message' + (Number(item.id) === targetId ? ' is-target' : '');
                article.append(text('strong', item.sender?.name || 'Unavailable account'));
                article.append(text('p', item.body || '[' + String(item.message_type || 'message') + ']'));
                article.append(text('time', item.created_at || ''));
                box.append(article);
            });
            box.hidden = false;
            box.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            status('Context loaded.');
        } catch (error) { status(error.message); }
        finally { state.busy = false; }
    };

    trigger.addEventListener('click', () => state.open ? close() : open());
    panel.querySelector('[data-snms-close]').addEventListener('click', close);
    el('snms-form').addEventListener('submit', event => { event.preventDefault(); load(false); });
    el('snms-more').addEventListener('click', () => load(true));
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && state.open) close(); });
    window.addEventListener('popstate', () => { const id = conversationId(); if (id !== state.conversationId) { state.conversationId = id; clear(); close(); } });
    document.addEventListener('click', () => window.setTimeout(() => {
        const id = conversationId();
        if (id !== state.conversationId) { state.conversationId = id; clear(); if (state.open) close(); }
    }, 0), true);
})();
