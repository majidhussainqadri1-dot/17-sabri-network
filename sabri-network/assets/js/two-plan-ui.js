(() => {
  'use strict';

  const messagesRoot = document.getElementById('sn-messages-root');
  const networkRoot = document.getElementById('sn-network-root');
  if (!messagesRoot && !networkRoot) return;

  const cfg = messagesRoot ? (window.SN_MESSAGES_CONFIG || {}) : (window.SN_CONFIG || {});
  if (!cfg.restUrl || !(cfg.isLoggedIn ?? cfg.isLoggedIn === undefined)) return;

  const $ = (selector, scope = document) => scope.querySelector(selector);
  const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];
  const escapeHtml = (value = '') => String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const safeText = value => String(value ?? '');
  const uuid = () => {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID().toLowerCase();
    if (!window.crypto?.getRandomValues) throw new Error('Secure random identifiers are unavailable.');
    const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(v => v.toString(16).padStart(2, '0'));
    return `${hex.slice(0,4).join('')}-${hex.slice(4,6).join('')}-${hex.slice(6,8).join('')}-${hex.slice(8,10).join('')}-${hex.slice(10).join('')}`;
  };
  const formatDate = value => {
    if (!value) return '';
    const date = new Date(`${String(value).replace(' ', 'T')}Z`);
    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(undefined, {dateStyle:'medium',timeStyle:'short'}).format(date);
  };

  async function api(path, options = {}) {
    if (!navigator.onLine) throw new Error('You appear to be offline.');
    const method = String(options.method || 'GET').toUpperCase();
    const headers = {'X-WP-Nonce': cfg.nonce || ''};
    let body = options.body;
    if (!['GET','HEAD'].includes(method)) {
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
    const response = await fetch(`${cfg.restUrl}${String(path).replace(/^\//, '')}`, {
      method, credentials:'same-origin', headers, body,
    });
    let data = {};
    try { data = await response.json(); } catch (_) {}
    if (!response.ok) {
      const error = new Error(data.message || 'The communication request could not be completed.');
      error.code = data.code || 'request_failed'; error.status = response.status; throw error;
    }
    return data;
  }

  function toast(message, type = 'info') {
    let region = document.getElementById('sntp-toast-region');
    if (!region) {
      region = document.createElement('div'); region.id = 'sntp-toast-region'; region.className = 'sntp-toast-region'; region.setAttribute('aria-live','assertive'); document.body.append(region);
    }
    const item = document.createElement('div'); item.className = `sntp-toast sntp-${type}`; item.textContent = message; region.append(item);
    requestAnimationFrame(() => item.classList.add('is-visible'));
    setTimeout(() => { item.classList.remove('is-visible'); setTimeout(() => item.remove(), 220); }, 4200);
  }

  function modal(title, html, ready) {
    closeModal();
    const wrap = document.createElement('div'); wrap.id = 'sntp-modal'; wrap.className = 'sntp-modal';
    wrap.innerHTML = `<div class="sntp-modal-backdrop" data-sntp-close></div><section class="sntp-modal-card" role="dialog" aria-modal="true" aria-labelledby="sntp-modal-title"><header><h2 id="sntp-modal-title">${escapeHtml(title)}</h2><button type="button" class="sntp-icon" data-sntp-close aria-label="Close">×</button></header><div class="sntp-modal-body">${html}</div></section>`;
    document.body.append(wrap); document.body.classList.add('sntp-modal-open');
    $$('[data-sntp-close]', wrap).forEach(button => button.addEventListener('click', closeModal));
    const card = $('.sntp-modal-card', wrap); const close = $('[data-sntp-close]', card); close?.focus();
    const keydown = event => {
      if (event.key === 'Escape') { closeModal(); return; }
      if (event.key !== 'Tab') return;
      const focusable = $$('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])', card).filter(el => !el.hidden);
      if (!focusable.length) return; const first = focusable[0], last = focusable.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    };
    wrap._sntpKeydown = keydown; document.addEventListener('keydown', keydown);
    if (ready) ready($('.sntp-modal-body', wrap));
  }

  function closeModal() {
    const wrap = document.getElementById('sntp-modal');
    if (!wrap) return;
    if (wrap._sntpKeydown) document.removeEventListener('keydown', wrap._sntpKeydown);
    wrap.remove(); document.body.classList.remove('sntp-modal-open');
  }

  function requestCard(item, incoming) {
    const other = incoming ? item.requester : item.recipient;
    const actions = incoming && item.status === 'pending'
      ? `<div class="sntp-row"><button type="button" class="sntp-btn" data-sntp-request-action="accept" data-id="${item.id}">Accept</button><button type="button" class="sntp-btn" data-sntp-request-action="decline" data-id="${item.id}">Decline</button><button type="button" class="sntp-btn sntp-danger" data-sntp-request-action="report" data-id="${item.id}">Report</button></div>`
      : '';
    return `<article class="sntp-card"><div class="sntp-card-head"><strong>${escapeHtml(other?.name || `User ${other?.id || ''}`)}</strong><span>${escapeHtml(item.status)}</span></div><p>${escapeHtml(item.message || '')}</p>${item.reason ? `<small>${escapeHtml(item.reason)}</small>` : ''}${actions}</article>`;
  }

  async function renderRequests(container) {
    container.dataset.sntpRendered = '1';
    container.innerHTML = '<div class="sntp-loading" role="status">Loading message requests…</div>';
    try {
      const [incoming, outgoing] = await Promise.all([api('message-requests?scope=incoming&status=pending'), api('message-requests?scope=outgoing&status=pending')]);
      container.innerHTML = `<section class="sntp-panel"><header class="sntp-panel-head"><h3>Message requests</h3><button type="button" class="sntp-btn" id="sntp-new-request">New request</button></header><p class="sntp-help">Unknown senders remain outside your canonical direct-message thread until you accept.</p><h4>Incoming</h4>${(incoming.items || []).map(item => requestCard(item,true)).join('') || '<p>No pending incoming requests.</p>'}<h4>Outgoing</h4>${(outgoing.items || []).map(item => requestCard(item,false)).join('') || '<p>No pending outgoing requests.</p>'}</section>`;
      $('#sntp-new-request', container)?.addEventListener('click', requestComposer);
      $$('[data-sntp-request-action]', container).forEach(button => button.addEventListener('click', async () => {
        const action = button.dataset.sntpRequestAction; const id = Number(button.dataset.id);
        try {
          const payload = {action};
          if (action === 'report') { payload.category = 'spam'; payload.details = 'Reported from the protected message-request inbox.'; }
          await api(`message-requests/${id}`, {method:'POST', body:payload}); toast(`Request ${action}ed.`,'success'); await renderRequests(container);
        } catch (error) { toast(error.message,'error'); }
      }));
    } catch (error) { container.innerHTML = `<p class="sntp-error">${escapeHtml(error.message)}</p>`; }
  }

  function requestComposer() {
    modal('New message request', `<form id="sntp-request-form" class="sntp-form"><label>Recipient user ID<input name="user_id" type="number" min="1" required></label><label>First message<textarea name="message" maxlength="4000" required></textarea></label><label>Reason / context (optional)<input name="reason" maxlength="500"></label><button type="submit" class="sntp-btn sntp-primary">Send request</button></form>`, body => {
      $('#sntp-request-form', body).addEventListener('submit', async event => {
        event.preventDefault(); const form = new FormData(event.currentTarget);
        try { await api('message-requests',{method:'POST',body:{user_id:Number(form.get('user_id')),message:String(form.get('message')||''),reason:String(form.get('reason')||'')}}); closeModal(); toast('Message request sent.','success'); }
        catch (error) { toast(error.message,'error'); }
      });
    });
  }

  function initNetworkRequests() {
    if (!networkRoot || networkRoot.dataset.authenticated !== '1') return;
    const tabs = $('.sn-tabs', networkRoot); const sidebar = $('#sn-sidebar-content', networkRoot);
    if (!tabs || !sidebar || $('#sntp-network-requests-tab', tabs)) return;
    const button = document.createElement('button'); button.type='button'; button.id='sntp-network-requests-tab'; button.className='sn-tab'; button.textContent='Requests'; button.dataset.sntpTab='requests';
    const chats = $('[data-tab="chats"]', tabs); chats?.insertAdjacentElement('afterend',button);
    let active = false; let rendering = false;
    button.addEventListener('click', async () => {
      active = true; $$('.sn-tab', tabs).forEach(tab => { tab.classList.toggle('is-active', tab === button); tab.toggleAttribute('aria-current', tab === button); });
      rendering = true; await renderRequests(sidebar); rendering = false;
    });
    $$('.sn-tab', tabs).filter(tab => tab !== button).forEach(tab => tab.addEventListener('click', () => { active=false; delete sidebar.dataset.sntpRendered; }));
    new MutationObserver(() => {
      if (!active || rendering || sidebar.dataset.sntpRendered === '1') return;
      rendering = true; renderRequests(sidebar).finally(() => { rendering=false; });
    }).observe(sidebar,{childList:true,subtree:false,attributes:true,attributeFilter:['data-sntp-rendered']});

    const actions = $('.sn-sidebar-actions', networkRoot);
    if (actions && !$('#sntp-community-center', actions)) {
      const hub = document.createElement('button'); hub.type='button'; hub.id='sntp-community-center'; hub.className='sn-icon-btn'; hub.setAttribute('aria-label','Community center'); hub.title='Community center'; hub.textContent='◎'; actions.prepend(hub); hub.addEventListener('click', openCommunityCenter);
    }
  }

  async function openCommunityCenter() {
    modal('Community center','<div id="sntp-community-content" class="sntp-loading" role="status">Loading communities…</div>');
    const content = $('#sntp-community-content');
    try {
      const data = await api('spaces?limit=100');
      const items = data.items || [];
      content.className=''; content.innerHTML = `<div class="sntp-community-grid"><aside><h3>Communities, groups and channels</h3>${items.map(space => `<button type="button" class="sntp-space-button" data-space-id="${space.id}"><strong>${escapeHtml(space.name)}</strong><small>${escapeHtml(space.type)} · ${escapeHtml(space.visibility)} · ${escapeHtml(space.state)}</small></button>`).join('') || '<p>No discoverable spaces.</p>'}</aside><section id="sntp-space-detail"><p>Select a space to view its governed collaboration tools.</p></section></div>`;
      $$('.sntp-space-button',content).forEach(button => button.addEventListener('click',()=>loadSpaceDetail(Number(button.dataset.spaceId))));
    } catch (error) { content.innerHTML=`<p class="sntp-error">${escapeHtml(error.message)}</p>`; }
  }

  async function loadSpaceDetail(spaceId) {
    const detail = $('#sntp-space-detail'); if (!detail) return; detail.innerHTML='<div class="sntp-loading">Loading space…</div>';
    try {
      const [spacePayload, settings, artifacts, health] = await Promise.all([
        api(`spaces/${spaceId}`), api(`spaces/${spaceId}/community-settings`).catch(()=>({})), api(`spaces/${spaceId}/community-artifacts?limit=50`).catch(()=>({items:[]})), api(`spaces/${spaceId}/community-health`).catch(()=>null),
      ]);
      const space = spacePayload.space || {};
      detail.innerHTML = `<header class="sntp-panel-head"><div><h3>${escapeHtml(space.name || 'Space')}</h3><p>${escapeHtml(space.description || '')}</p></div><button type="button" class="sntp-btn" id="sntp-space-join">Join / request</button></header>${health ? `<div class="sntp-metrics"><span>Members <b>${Number(health.member_count||0)}</b></span><span>Questions <b>${Number(health.open_questions||0)}</b></span><span>Answered <b>${Number(health.answered_questions||0)}</b></span><span>Reports 30d <b>${Number(health.report_count_30d||0)}</b></span></div>`:''}<section class="sntp-card"><h4>Rules and onboarding</h4><p>${escapeHtml(settings.rules || space.rules || 'No rules published.')}</p>${Array.isArray(settings.join_questions)&&settings.join_questions.length?`<ol>${settings.join_questions.map(q=>`<li>${escapeHtml(q)}</li>`).join('')}</ol>`:''}<p>${escapeHtml(settings.orientation || '')}</p><button type="button" class="sntp-btn" id="sntp-edit-community-settings">Edit rules/onboarding</button></section><section><div class="sntp-panel-head"><h4>Forum, AMA, Wiki and Events</h4><button type="button" class="sntp-btn" id="sntp-new-artifact">New item</button></div><div id="sntp-artifact-list">${(artifacts.items||[]).map(artifactCard).join('') || '<p>No collaboration items yet.</p>'}</div></section>`;
      $('#sntp-space-join',detail)?.addEventListener('click',async()=>{try{await api(`spaces/${spaceId}/join`,{method:'POST',body:{reason:'Joined from Community center'}});toast('Join request processed.','success');}catch(error){toast(error.message,'error');}});
      $('#sntp-edit-community-settings',detail)?.addEventListener('click',()=>communitySettingsDialog(spaceId,settings,space));
      $('#sntp-new-artifact',detail)?.addEventListener('click',()=>communityArtifactDialog(spaceId));
      $$('[data-artifact-id]',detail).forEach(button=>button.addEventListener('click',()=>artifactDialog(spaceId,Number(button.dataset.artifactId),button.dataset.artifactTitle||'')));
    } catch (error) { detail.innerHTML=`<p class="sntp-error">${escapeHtml(error.message)}</p>`; }
  }

  function artifactCard(item) {
    return `<button type="button" class="sntp-artifact" data-artifact-id="${item.id}" data-artifact-title="${escapeHtml(item.title)}"><span><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.type)} · ${Number(item.response_count||0)} responses</small></span><span aria-hidden="true">›</span></button>`;
  }

  function communitySettingsDialog(spaceId, settings, space) {
    modal('Community rules and onboarding',`<form id="sntp-community-settings-form" class="sntp-form"><label>Rules<textarea name="rules" maxlength="10000">${escapeHtml(settings.rules||space.rules||'')}</textarea></label><label>Join questions (one per line)<textarea name="questions">${escapeHtml((settings.join_questions||[]).join('\n'))}</textarea></label><label>Orientation<textarea name="orientation" maxlength="6000">${escapeHtml(settings.orientation||'')}</textarea></label><button class="sntp-btn sntp-primary">Save settings</button></form>`,body=>{
      $('#sntp-community-settings-form',body).addEventListener('submit',async event=>{event.preventDefault();const fd=new FormData(event.currentTarget);try{await api(`spaces/${spaceId}/community-settings`,{method:'POST',body:{rules:String(fd.get('rules')||''),join_questions:String(fd.get('questions')||'').split(/\r?\n/).map(v=>v.trim()).filter(Boolean),orientation:String(fd.get('orientation')||'')}});closeModal();toast('Community settings saved.','success');}catch(error){toast(error.message,'error');}});
    });
  }

  function communityArtifactDialog(spaceId) {
    modal('New community collaboration item',`<form id="sntp-artifact-form" class="sntp-form"><label>Type<select name="type"><option value="forum_question">Forum question</option><option value="ama_session">Expert AMA</option><option value="wiki_page">Community Wiki page</option><option value="event">Event / cohort</option></select></label><label>Title<input name="title" maxlength="191" required></label><label>Body<textarea name="body" maxlength="20000"></textarea></label><label>Starts at (events/AMA)<input name="starts_at" type="datetime-local"></label><label>Ends at<input name="ends_at" type="datetime-local"></label><button class="sntp-btn sntp-primary">Create</button></form>`,body=>{
      $('#sntp-artifact-form',body).addEventListener('submit',async event=>{event.preventDefault();const fd=new FormData(event.currentTarget);const local=value=>value?new Date(String(value)).toISOString():'';try{await api(`spaces/${spaceId}/community-artifacts`,{method:'POST',body:{type:String(fd.get('type')),title:String(fd.get('title')||''),body:String(fd.get('body')||''),starts_at:local(fd.get('starts_at')),ends_at:local(fd.get('ends_at'))}});closeModal();toast('Community item created.','success');}catch(error){toast(error.message,'error');}});
    });
  }

  async function artifactDialog(spaceId,artifactId,title) {
    modal(title||'Community item','<div id="sntp-artifact-detail" class="sntp-loading">Loading responses…</div>');
    const detail=$('#sntp-artifact-detail');
    try{const data=await api(`spaces/${spaceId}/community-artifacts/${artifactId}/responses?limit=100`);detail.className='';detail.innerHTML=`<div class="sntp-responses">${(data.items||[]).map(r=>`<article class="sntp-card"><strong>${escapeHtml(r.user?.name||'Member')}</strong><p>${escapeHtml(r.body)}</p><small>${escapeHtml(formatDate(r.created_at))}</small></article>`).join('')||'<p>No responses yet.</p>'}</div><form id="sntp-response-form" class="sntp-form"><label>Response<textarea name="body" maxlength="10000" required></textarea></label><button class="sntp-btn sntp-primary">Respond</button></form><div class="sntp-row"><button type="button" class="sntp-btn" data-mod="close">Close item</button><button type="button" class="sntp-btn" data-mod="reopen">Reopen</button></div>`;$('#sntp-response-form',detail).addEventListener('submit',async event=>{event.preventDefault();const fd=new FormData(event.currentTarget);try{await api(`spaces/${spaceId}/community-artifacts/${artifactId}/respond`,{method:'POST',body:{body:String(fd.get('body')||'')}});await artifactDialog(spaceId,artifactId,title);}catch(error){toast(error.message,'error');}});$$('[data-mod]',detail).forEach(btn=>btn.addEventListener('click',async()=>{try{await api(`spaces/${spaceId}/community-artifacts/${artifactId}/moderate`,{method:'POST',body:{action:btn.dataset.mod}});toast('Moderation state updated.','success');}catch(error){toast(error.message,'error');}}));}catch(error){detail.innerHTML=`<p class="sntp-error">${escapeHtml(error.message)}</p>`;}
  }

  function currentConversationId() {
    const query = new URLSearchParams(window.location.search); const id = Number(query.get('conversation') || 0); return id > 0 ? id : 0;
  }

  function messagesUrlWithConversation(id) {
    const url = new URL(window.location.href); url.searchParams.set('conversation',String(id)); return url.toString();
  }

  function initMessagesFilters() {
    if (!messagesRoot || messagesRoot.dataset.authenticated !== '1') return;
    const filters = $('.snm-filters', messagesRoot); const list=$('#sn-messages-conversation-list',messagesRoot); if(!filters||!list)return;
    const custom=[['direct','Direct'],['group','Groups'],['channel','Channels'],['requests','Requests'],['starred','Starred']]; let active=''; let rendering=false;
    custom.forEach(([key,label])=>{if($(`[data-sntp-filter="${key}"]`,filters))return;const b=document.createElement('button');b.type='button';b.className='snm-filter';b.dataset.sntpFilter=key;b.textContent=label;filters.append(b);b.addEventListener('click',async()=>{active=key;$$('.snm-filter',filters).forEach(x=>x.classList.toggle('is-active',x===b));rendering=true;await renderMessagesCustomFilter(key,list);rendering=false;});});
    $$('.snm-filter',filters).filter(b=>!b.dataset.sntpFilter).forEach(b=>b.addEventListener('click',()=>{active='';delete list.dataset.sntpRendered;}));
    new MutationObserver(()=>{if(!active||rendering||list.dataset.sntpRendered==='1')return;rendering=true;renderMessagesCustomFilter(active,list).finally(()=>{rendering=false;});}).observe(list,{childList:true,subtree:false,attributes:true,attributeFilter:['data-sntp-rendered']});
  }

  async function renderMessagesCustomFilter(filter,list) {
    list.dataset.sntpRendered='1';list.innerHTML='<p class="sntp-loading">Loading…</p>';
    try{
      if(filter==='requests'){await renderRequests(list);return;}
      if(filter==='starred'){
        const data=await api('messages/starred?limit=100');list.innerHTML=(data.items||[]).map(item=>`<button type="button" class="sntp-starred-item" data-conversation="${item.conversation_id}"><strong>${escapeHtml(item.sender?.name||'Message')}</strong><span>${escapeHtml(item.body||`[${item.message_type}]`)}</span><small>${escapeHtml(formatDate(item.created_at))}</small></button>`).join('')||'<p>No starred messages.</p>';$$('[data-conversation]',list).forEach(b=>b.addEventListener('click',()=>{window.location.href=messagesUrlWithConversation(Number(b.dataset.conversation));}));return;
      }
      const data=await api('conversations');const items=(data.conversations||[]).filter(item=>item.type===filter&&!item.archived);list.innerHTML=items.map(item=>`<button type="button" class="sntp-conversation-link" data-conversation="${item.id}"><img src="${escapeHtml(item.avatar||'')}" alt=""><span><strong>${escapeHtml(item.title||item.type)}</strong><small>${escapeHtml(item.last_message?.body||item.description||'No messages yet')}</small></span>${item.unread_count?`<b>${Number(item.unread_count)}</b>`:''}</button>`).join('')||`<p>No ${escapeHtml(filter)} conversations.</p>`;$$('[data-conversation]',list).forEach(b=>b.addEventListener('click',()=>{window.location.href=messagesUrlWithConversation(Number(b.dataset.conversation));}));
    }catch(error){list.innerHTML=`<p class="sntp-error">${escapeHtml(error.message)}</p>`;}
  }

  function initComposerTools() {
    if (!messagesRoot || messagesRoot.dataset.authenticated !== '1') return;
    const composer=$('#snm-composer',messagesRoot);if(!composer||$('#sntp-composer-tools',messagesRoot))return;
    const tools=document.createElement('div');tools.id='sntp-composer-tools';tools.className='sntp-composer-tools';tools.setAttribute('aria-label','Advanced message tools');tools.innerHTML='<button type="button" data-tool="schedule">Schedule</button><button type="button" data-tool="poll">Poll</button><button type="button" data-tool="checklist">Checklist</button><button type="button" data-tool="voice">Voice note</button>';
    composer.insertAdjacentElement('beforebegin',tools);$$('[data-tool]',tools).forEach(button=>button.addEventListener('click',()=>openComposerTool(button.dataset.tool)));
  }

  function requireConversation() { const id=currentConversationId(); if(!id)toast('Open a conversation first.','error'); return id; }

  function openComposerTool(tool) {
    const id=requireConversation();if(!id)return;
    if(tool==='schedule'){
      modal('Schedule message',`<form id="sntp-schedule-form" class="sntp-form"><label>Message<textarea name="body" maxlength="10000" required></textarea></label><label>Deliver at<input name="deliver_at" type="datetime-local" required></label><button class="sntp-btn sntp-primary">Schedule</button></form>`,body=>$('#sntp-schedule-form',body).addEventListener('submit',async e=>{e.preventDefault();const fd=new FormData(e.currentTarget);try{await api(`conversations/${id}/scheduled-messages`,{method:'POST',body:{body:String(fd.get('body')||''),deliver_at:new Date(String(fd.get('deliver_at'))).toISOString()}});closeModal();toast('Message scheduled.','success');}catch(error){toast(error.message,'error');}}));return;
    }
    if(tool==='poll'){
      modal('Create poll',`<form id="sntp-poll-form" class="sntp-form"><label>Question<input name="question" maxlength="500" required></label><label>Options (one per line)<textarea name="options" required></textarea></label><p class="sntp-help">Polls are collaborative communication tools and never clinical decision authority.</p><button class="sntp-btn sntp-primary">Create poll</button></form>`,body=>$('#sntp-poll-form',body).addEventListener('submit',async e=>{e.preventDefault();const fd=new FormData(e.currentTarget);try{await api(`conversations/${id}/polls`,{method:'POST',body:{question:String(fd.get('question')||''),options:String(fd.get('options')||'').split(/\r?\n/).map(v=>v.trim()).filter(Boolean)}});closeModal();toast('Poll created.','success');}catch(error){toast(error.message,'error');}}));return;
    }
    if(tool==='checklist'){
      modal('Create checklist',`<form id="sntp-checklist-form" class="sntp-form"><label>Title<input name="title" maxlength="500" required></label><label>Items (one per line)<textarea name="items" required></textarea></label><p class="sntp-help">Checklists support collaboration; they do not authorize diagnosis or prescribing.</p><button class="sntp-btn sntp-primary">Create checklist</button></form>`,body=>$('#sntp-checklist-form',body).addEventListener('submit',async e=>{e.preventDefault();const fd=new FormData(e.currentTarget);try{await api(`conversations/${id}/checklists`,{method:'POST',body:{title:String(fd.get('title')||''),items:String(fd.get('items')||'').split(/\r?\n/).map(v=>v.trim()).filter(Boolean)}});closeModal();toast('Checklist created.','success');}catch(error){toast(error.message,'error');}}));return;
    }
    if(tool==='voice') openVoiceRecorder(id);
  }

  function openVoiceRecorder(conversationId) {
    modal('Record voice note','<div class="sntp-voice"><p id="sntp-voice-status">Ready to record.</p><audio id="sntp-voice-preview" controls hidden></audio><div class="sntp-row"><button type="button" class="sntp-btn" id="sntp-record-start">Start recording</button><button type="button" class="sntp-btn" id="sntp-record-stop" disabled>Stop</button><button type="button" class="sntp-btn" id="sntp-record-cancel" disabled>Cancel recording</button><button type="button" class="sntp-btn sntp-primary" id="sntp-record-send" disabled>Send voice note</button></div></div>',body=>{
      let recorder=null,stream=null,chunks=[],blob=null;const status=$('#sntp-voice-status',body),preview=$('#sntp-voice-preview',body),start=$('#sntp-record-start',body),stop=$('#sntp-record-stop',body),cancel=$('#sntp-record-cancel',body),send=$('#sntp-record-send',body);
      const reset=()=>{stream?.getTracks().forEach(t=>t.stop());stream=null;recorder=null;chunks=[];blob=null;preview.hidden=true;preview.removeAttribute('src');start.disabled=false;stop.disabled=true;cancel.disabled=true;send.disabled=true;status.textContent='Ready to record.';};
      start.addEventListener('click',async()=>{try{stream=await navigator.mediaDevices.getUserMedia({audio:true});const type=MediaRecorder.isTypeSupported('audio/webm;codecs=opus')?'audio/webm;codecs=opus':'audio/webm';recorder=new MediaRecorder(stream,{mimeType:type});chunks=[];recorder.ondataavailable=e=>{if(e.data?.size)chunks.push(e.data);};recorder.onstop=()=>{blob=new Blob(chunks,{type});preview.src=URL.createObjectURL(blob);preview.hidden=false;send.disabled=!blob.size;stream?.getTracks().forEach(t=>t.stop());stream=null;status.textContent='Review the recording before sending.';};recorder.start(1000);start.disabled=true;stop.disabled=false;cancel.disabled=false;status.textContent='Recording…';}catch(error){toast(error.message||'Microphone permission was not granted.','error');}});
      stop.addEventListener('click',()=>{if(recorder?.state==='recording')recorder.stop();stop.disabled=true;cancel.disabled=false;});cancel.addEventListener('click',()=>{if(recorder?.state==='recording')recorder.stop();reset();});
      send.addEventListener('click',async()=>{if(!blob)return;const form=new FormData();form.set('attachment',new File([blob],'voice-note.webm',{type:blob.type||'audio/webm'}));try{await api(`conversations/${conversationId}/voice-notes`,{method:'POST',body:form});closeModal();toast('Voice note sent.','success');}catch(error){toast(error.message,'error');}});
    });
  }

  function initMessageActions() {
    if(!messagesRoot)return;const list=$('#snm-message-list',messagesRoot);if(!list)return;
    const enhance=()=>$$('.snm-message',list).forEach(article=>{if($('.sntp-message-tools',article))return;const id=Number(article.dataset.messageId||0);if(!id)return;const own=article.classList.contains('is-own');const tools=document.createElement('div');tools.className='sntp-message-tools';tools.innerHTML=`<button type="button" data-action="reply">Reply</button><button type="button" data-action="like">👍</button><button type="button" data-action="star">Star</button><button type="button" data-action="translate">Translate</button><button type="button" data-action="forward">Forward</button><button type="button" data-action="details">Details</button>${own?'<button type="button" data-action="expiry">Expiry</button>':''}`;article.append(tools);$$('[data-action]',tools).forEach(button=>button.addEventListener('click',()=>messageAction(id,button.dataset.action,own)));});enhance();new MutationObserver(enhance).observe(list,{childList:true,subtree:false});
  }

  async function messageAction(id,action,own) {
    const conversationId=currentConversationId();if(!conversationId)return;
    try{
      if(action==='like'){await api(`messages/${id}/reaction`,{method:'POST',body:{reaction:'👍'}});toast('Reaction saved.','success');return;}
      if(action==='star'){await api(`messages/${id}/star`,{method:'POST',body:{starred:true}});toast('Message starred.','success');return;}
      if(action==='reply'){modal('Reply to message',`<form id="sntp-reply-form" class="sntp-form"><label>Reply<textarea name="body" maxlength="10000" required></textarea></label><button class="sntp-btn sntp-primary">Send reply</button></form>`,body=>$('#sntp-reply-form',body).addEventListener('submit',async e=>{e.preventDefault();const fd=new FormData();fd.set('body',String(new FormData(e.currentTarget).get('body')||''));fd.set('reply_to',String(id));try{await api(`conversations/${conversationId}/messages`,{method:'POST',body:fd});closeModal();toast('Reply sent.','success');}catch(error){toast(error.message,'error');}}));return;}
      if(action==='translate'){translateDialog(id);return;}
      if(action==='forward'){modal('Forward message',`<form id="sntp-forward-form" class="sntp-form"><label>Target conversation ID<input name="conversation_id" type="number" min="1" required></label><p class="sntp-help">Private attachments are not silently reused across audiences.</p><button class="sntp-btn sntp-primary">Forward</button></form>`,body=>$('#sntp-forward-form',body).addEventListener('submit',async e=>{e.preventDefault();const target=Number(new FormData(e.currentTarget).get('conversation_id'));try{await api(`messages/${id}/forward`,{method:'POST',body:{conversation_id:target}});closeModal();toast('Message forwarded safely.','success');}catch(error){toast(error.message,'error');}}));return;}
      if(action==='expiry'&&own){expiryDialog(id);return;}
      if(action==='details'){await structuredDialog(id);}
    }catch(error){toast(error.message,'error');}
  }

  function translateDialog(id) {
    modal('Translate message',`<form id="sntp-translate-form" class="sntp-form"><label>Target language<select name="language"><option value="ur">اردو</option><option value="en-US">English (US)</option><option value="ar">العربية</option><option value="zh-CN">中文</option><option value="es">Español</option><option value="fr">Français</option></select></label><button class="sntp-btn sntp-primary">Translate</button></form><div id="sntp-translation-result"></div>`,body=>$('#sntp-translate-form',body).addEventListener('submit',async e=>{e.preventDefault();const language=String(new FormData(e.currentTarget).get('language'));try{const data=await api(`messages/${id}/translate`,{method:'POST',body:{target_language:language}});$('#sntp-translation-result',body).innerHTML=`<div class="sntp-card"><p>${escapeHtml(data.text||'')}</p><small>Provider: ${escapeHtml(data.provider||'approved adapter')} · source not persisted</small></div>`;}catch(error){toast(error.message,'error');}}));
  }

  function expiryDialog(id) {
    modal('Disappearing-message expiry',`<form id="sntp-expiry-form" class="sntp-form"><label>Expiry<select name="seconds"><option value="0">Off</option><option value="3600">1 hour</option><option value="86400">1 day</option><option value="604800">7 days</option><option value="2592000">30 days</option></select></label><p class="sntp-help">Legal/safety holds override ordinary expiry.</p><button class="sntp-btn sntp-primary">Save</button></form>`,body=>$('#sntp-expiry-form',body).addEventListener('submit',async e=>{e.preventDefault();try{await api(`messages/${id}/expiry`,{method:'POST',body:{seconds:Number(new FormData(e.currentTarget).get('seconds'))}});closeModal();toast('Expiry policy saved.','success');}catch(error){toast(error.message,'error');}}));
  }

  async function structuredDialog(id) {
    const data=await api(`messages/${id}/structured`);
    if(data.poll){modal('Poll details',`<div id="sntp-poll-details"><h3>${escapeHtml(data.poll.question)}</h3>${data.poll.options.map((option,index)=>`<button type="button" class="sntp-poll-option" data-option="${index}" aria-pressed="${data.poll.viewer_vote===index?'true':'false'}"><span>${escapeHtml(option)}</span><b>${Number(data.poll.counts[index]||0)}</b></button>`).join('')}<p class="sntp-help">This poll is not clinical decision authority.</p></div>`,body=>$$('[data-option]',body).forEach(button=>button.addEventListener('click',async()=>{try{await api(`messages/${id}/poll-vote`,{method:'POST',body:{option:Number(button.dataset.option)}});closeModal();await structuredDialog(id);}catch(error){toast(error.message,'error');}})));return;}
    if(data.checklist){modal('Checklist details',`<div id="sntp-checklist-details"><h3>${escapeHtml(data.checklist.title)}</h3>${data.checklist.items.map(item=>`<label class="sntp-check-item"><input type="checkbox" data-item="${item.index}" ${item.done?'checked':''}><span>${escapeHtml(item.label)}</span></label>`).join('')}<p class="sntp-help">This checklist is collaborative only; it is not clinical decision authority.</p></div>`,body=>$$('[data-item]',body).forEach(input=>input.addEventListener('change',async()=>{try{await api(`messages/${id}/checklist-items/${Number(input.dataset.item)}`,{method:'POST',body:{done:input.checked}});}catch(error){input.checked=!input.checked;toast(error.message,'error');}})));return;}
    const voice=data.voice_note;modal('Message details',`<dl class="sntp-details"><dt>Type</dt><dd>${escapeHtml(data.message_type||'text')}</dd><dt>Expires</dt><dd>${escapeHtml(data.expires_at?formatDate(data.expires_at):'No ordinary expiry')}</dd>${voice?`<dt>Voice-note transcript</dt><dd>${escapeHtml(voice.transcript_available?voice.transcript:'No transcript available')}</dd><dt>Playback speeds</dt><dd>${escapeHtml((voice.playback_speeds||[]).join('×, '))}</dd>`:''}</dl>`);
  }

  initNetworkRequests();
  initMessagesFilters();
  initComposerTools();
  initMessageActions();
})();
