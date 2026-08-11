(() => {
  'use strict';
  const root = document.getElementById('sn-future-workspace');
  if (!root) return;
  const cfg = window.SN_FUTURE_CONFIG || {};
  const status = document.getElementById('snf-status');
  const api = async (path, options = {}) => {
    const headers = {'X-WP-Nonce': cfg.nonce || ''};
    let body = options.body;
    if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    const response = await fetch(`${cfg.restUrl || ''}${String(path).replace(/^\//, '')}`, {credentials:'same-origin', method:options.method || 'GET', headers, body});
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
  bind('snf-reminder-form', data => { const local=String(data.get('remind_at')||'');const parsed=local?new Date(local):null;return api('future/reminders',{method:'POST',body:{conversation_id:Number(data.get('conversation_id')),remind_at:parsed&&!Number.isNaN(parsed.getTime())?parsed.toISOString():local,label:String(data.get('label')||''),client_id:crypto.randomUUID?.()||`reminder-${Date.now()}`}}); });
  bind('snf-template-form', data => api('future/templates',{method:'POST',body:{title:String(data.get('title')||''),body:String(data.get('body')||''),client_id:crypto.randomUUID?.()||`template-${Date.now()}`}}));
  loadCapabilities();
})();
