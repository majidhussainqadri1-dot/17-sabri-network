(() => {
  'use strict';
  const root = document.getElementById('sn-future-workspace');
  if (!root) return;
  const cfg = window.SN_FUTURE_CONFIG || {};
  const status = document.getElementById('snf-status');
  const uuid = () => {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID().toLowerCase();
    if (!window.crypto?.getRandomValues) throw new Error('Secure random identifiers are unavailable.');
    const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(v => v.toString(16).padStart(2, '0'));
    return `${hex.slice(0,4).join('')}-${hex.slice(4,6).join('')}-${hex.slice(6,8).join('')}-${hex.slice(8,10).join('')}-${hex.slice(10).join('')}`;
  };
  const api = async (path, options = {}) => {
    const headers = {'X-WP-Nonce': cfg.nonce || ''};
    const method = String(options.method || 'GET').toUpperCase();
    let body = options.body;
    if (!['GET','HEAD'].includes(method)) {
      const key = String(options.idempotencyKey || uuid()).toLowerCase();
      headers['Idempotency-Key'] = key;
      if (body instanceof FormData) { if (!body.has('client_id')) body.set('client_id', key); }
      else body = {...(body || {}), client_id: body?.client_id || key};
    }
    if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    const response = await fetch(`${cfg.restUrl || ''}${String(path).replace(/^\//, '')}`, {credentials:'same-origin', method, headers, body});
    let data = {}; try { data = await response.json(); } catch (_) {}
    if (!response.ok) throw new Error(data.message || 'The advanced communication request failed.');
    return data;
  };
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const announce = (message, error = false) => { status.className = error ? 'snf-status snf-error' : 'snf-status'; status.textContent = message; };
  const featureList = items => `<div class="snf-feature-list">${items.map(item => `<article class="snf-feature"><div><strong>${esc(item.id)} — ${esc(item.label)}</strong><small>${esc(item.phase)}${item.provider ? ` · provider: ${esc(item.provider)}` : ''}</small></div><span class="snf-badge snf-${esc(item.status)}">${esc(item.status)}</span></article>`).join('')}</div>`;
  async function loadCapabilities() { try { const data = await api('future/capabilities'); status.innerHTML = `<strong>${Number(data.feature_count || 0)} advanced File 17 capabilities</strong>${featureList(data.items || [])}`; status.className='snf-status'; } catch (error) { announce(error.message,true); } }
  const bind = (id, handler) => { const form=document.getElementById(id); if(!form)return; form.addEventListener('submit',async event=>{event.preventDefault();const button=form.querySelector('button[type="submit"]');if(button)button.disabled=true;try{await handler(new FormData(form));announce('Saved successfully.');if(id!=='snf-lock-form')form.reset();}catch(error){announce(error.message,true);}finally{if(button)button.disabled=false;}}); };
  bind('snf-lock-form', data => api(`future/conversation-locks/${Number(data.get('conversation_id'))}`, {method:'POST', body:{enabled:data.get('enabled')==='on'}}));
  bind('snf-reminder-form', data => { const local=String(data.get('remind_at')||'');const parsed=local?new Date(local):null;return api('future/reminders',{method:'POST',body:{conversation_id:Number(data.get('conversation_id')),remind_at:parsed&&!Number.isNaN(parsed.getTime())?parsed.toISOString():local,label:String(data.get('label')||'')}}); });
  bind('snf-template-form', data => api('future/templates',{method:'POST',body:{title:String(data.get('title')||''),body:String(data.get('body')||'')}}));
  // V12.3: additional governed UI actions, using only canonical File-17 APIs.
  const advancedGrid = root.querySelector('.snf-grid');
  const extraControls = [
    '<form id="snf-smart-view-form" class="snf-card"><h3>Saved private view</h3><label>View name<input name="name" maxlength="100" required></label><label><input name="unread" type="checkbox"> Unread only</label><label><input name="has_files" type="checkbox"> With files</label><label>Last number of days<input name="days" type="number" min="1" max="365" value="30" required></label><button type="submit">Save private view</button></form>',
    '<form id="snf-team-inbox-form" class="snf-card"><h3>Authorized team inbox</h3><p>Only conversation managers may assign an active participant.</p><label>Conversation ID<input name="conversation_id" type="number" min="1" required></label><label>Assignee user ID<input name="assignee_id" type="number" min="0" required></label><label>Queue status<select name="status"><option value="open">Open</option><option value="pending">Pending</option><option value="resolved">Resolved</option></select></label><label><input name="enabled" type="checkbox" checked> Enable team inbox</label><button type="submit">Save team assignment</button></form>',
    '<form id="snf-citation-form" class="snf-card"><h3>Scholarly citation card</h3><p>Link a File 06 or File 12 canonical source without copying protected content.</p><label>Conversation ID<input name="conversation_id" type="number" min="1" required></label><label>Source<select name="source_owner"><option value="file-06">Encyclopedia</option><option value="file-12">PDF Library</option></select></label><label>Canonical source ID<input name="canonical_id" maxlength="191" required></label><label>Source title<input name="title" maxlength="300" required></label><label>Page or locator<input name="locator" maxlength="120"></label><button type="submit">Add citation reference</button></form>',
    '<form id="snf-mentorship-form" class="snf-card"><h3>Mentorship invitation</h3><p>Eligibility, blocks and guardian approval are checked by the server.</p><label>Student user ID<input name="student_id" type="number" min="1" required></label><label>Shared conversation ID (optional)<input name="conversation_id" type="number" min="0" value="0"></label><button type="submit">Request mentorship</button></form>'
  ];
  if (advancedGrid) advancedGrid.insertAdjacentHTML('beforeend', extraControls.join(''));
  bind('snf-smart-view-form', data => api('future/smart-views', {method:'POST', body:{name:String(data.get('name') || ''), criteria:{unread:data.get('unread')==='on', has_files:data.get('has_files')==='on', days:Number(data.get('days') || 30)}}}));
  bind('snf-team-inbox-form', data => api(`future/team-inbox/${Number(data.get('conversation_id'))}`, {method:'POST', body:{assignee_id:Number(data.get('assignee_id') || 0), status:String(data.get('status') || 'open'), enabled:data.get('enabled')==='on'}}));
  bind('snf-citation-form', data => api('future/citations', {method:'POST', body:{conversation_id:Number(data.get('conversation_id')), source_owner:String(data.get('source_owner') || ''), canonical_id:String(data.get('canonical_id') || ''), title:String(data.get('title') || ''), locator:String(data.get('locator') || '')}}));
  bind('snf-mentorship-form', data => api('future/mentorships', {method:'POST', body:{student_id:Number(data.get('student_id')), conversation_id:Number(data.get('conversation_id') || 0)}}));
  loadCapabilities();
})();
