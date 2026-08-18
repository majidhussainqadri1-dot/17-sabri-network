(() => {
  'use strict';
  const cfg = window.SN_MESSAGES_CONFIG || {};
  if (!cfg.restUrl) return;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const uuid = () => {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID().toLowerCase();
    if (!window.crypto?.getRandomValues) throw new Error('Secure random identifiers are unavailable.');
    const b = new Uint8Array(16); window.crypto.getRandomValues(b); b[6]=(b[6]&15)|64; b[8]=(b[8]&63)|128;
    const h=[...b].map(v=>v.toString(16).padStart(2,'0')); return `${h.slice(0,4).join('')}-${h.slice(4,6).join('')}-${h.slice(6,8).join('')}-${h.slice(8,10).join('')}-${h.slice(10).join('')}`;
  };
  async function api(path, options={}) {
    const method=String(options.method||'GET').toUpperCase(), headers={'X-WP-Nonce':cfg.nonce||''}; let body=options.body;
    if (!['GET','HEAD'].includes(method)) { const key=uuid(); headers['Idempotency-Key']=key; headers['Content-Type']='application/json'; body=JSON.stringify({...body,client_id:key}); }
    const response=await fetch(`${cfg.restUrl}${String(path).replace(/^\//,'')}`,{credentials:'same-origin',method,headers,body});
    let data={}; try{data=await response.json();}catch(_){ }
    if(!response.ok) throw new Error(data.message||'The communication request failed.'); return data;
  }
  function dialog(messageId, version) {
    const wrap=document.createElement('div'); wrap.className='sn-round20-dialog'; wrap.setAttribute('role','dialog'); wrap.setAttribute('aria-modal','true'); wrap.setAttribute('aria-labelledby','sn-r20-title');
    wrap.innerHTML=`<div class="sn-round20-card"><h2 id="sn-r20-title">Disappearing-message expiry</h2><label>Expiry<select><option value="0">Off</option><option value="3600">1 hour</option><option value="86400">1 day</option><option value="604800">7 days</option><option value="2592000">30 days</option></select></label><p>Safety or legal holds override ordinary expiry.</p><div><button type="button" data-save>Save</button><button type="button" data-cancel>Cancel</button></div><p data-status role="status"></p></div>`;
    Object.assign(wrap.style,{position:'fixed',inset:'0',zIndex:'100000',background:'rgba(0,0,0,.45)',display:'grid',placeItems:'center',padding:'20px'});
    const card=wrap.querySelector('.sn-round20-card'); Object.assign(card.style,{background:'#fff',color:'#111',maxWidth:'420px',width:'100%',padding:'20px',borderRadius:'12px'});
    const close=()=>{wrap.remove();}; wrap.querySelector('[data-cancel]').addEventListener('click',close);
    wrap.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
    wrap.querySelector('[data-save]').addEventListener('click',async()=>{const save=wrap.querySelector('[data-save]'), status=wrap.querySelector('[data-status]'); save.disabled=true; status.textContent='Saving…'; try{const seconds=Number(wrap.querySelector('select').value); const result=await api(`messages/${messageId}/expiry`,{method:'POST',body:{seconds,expected_version:Number(version)}}); status.textContent=`Saved. Message version ${esc(result.version||version)}.`; setTimeout(close,500);}catch(error){status.textContent=error.message;save.disabled=false;}});
    document.body.appendChild(wrap); wrap.querySelector('select').focus();
  }
  document.addEventListener('click', async event => {
    const button=event.target.closest('button[data-action="expiry"]'); if(!button) return;
    const article=button.closest('.snm-message'); const id=Number(article?.dataset?.messageId||0); if(!id)return;
    event.preventDefault(); event.stopImmediatePropagation(); button.disabled=true;
    try { const data=await api(`messages/${id}/structured`); dialog(id,Number(data.version||1)); }
    catch(error){ window.alert(error.message); }
    finally{ button.disabled=false; }
  }, true);
})();
