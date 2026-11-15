'use strict';
/**
 * Native Node runtime contract for the V12.3 File-17 advanced workspace.
 * No browser or live data required. The DOM/fetch doubles intentionally
 * exercise generated-form registration, consent refusal, and output escaping.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
  constructor(tag = 'div') {
    this.tag = tag; this.children = []; this.hidden = false; this.className = '';
    this.textContent = ''; this.innerHTML = ''; this.attributes = {}; this.value = '';
  }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = [...nodes]; }
  insertAdjacentElement(_position, element) { this.children.push(element); }
  insertAdjacentHTML(_position, html) {
    for (const match of html.matchAll(/<form id="([^"]+)"/g)) {
      if (!forms.has(match[1])) forms.set(match[1], new Form(match[1]));
    }
  }
}
class Form extends Element {
  constructor(id) {
    super('form'); this.id = id; this.fields = {}; this.events = {};
    this.button = {disabled: false}; this.resets = 0;
  }
  addEventListener(name, handler) { this.events[name] = handler; }
  querySelector(selector) { return selector.includes('button') ? this.button : null; }
  reset() { this.resets += 1; }
  async submit(fields) {
    this.fields = fields;
    assert.equal(typeof this.events.submit, 'function', this.id + ' must be bound');
    await this.events.submit({preventDefault() {}});
  }
}
class FakeFormData {
  constructor(form) { this.fields = {...form.fields}; }
  get(name) { return Object.prototype.hasOwnProperty.call(this.fields, name) ? this.fields[name] : null; }
  has(name) { return Object.prototype.hasOwnProperty.call(this.fields, name); }
  set(name, value) { this.fields[name] = value; }
}

const forms = new Map();
for (const id of ['snf-lock-form','snf-reminder-form','snf-template-form']) forms.set(id, new Form(id));
const status = new Element();
const root = new Element();
const grid = new Element();
root.querySelector = selector => selector === '.snf-grid' ? grid : null;
const nodes = [root,status,grid];
const document = {
  getElementById(id) { if (id === 'sn-future-workspace') return root; if (id === 'snf-status') return status; return forms.get(id) || null; },
  createElement(tag) { const node = new Element(tag); nodes.push(node); return node; }
};
let confirmResult = false;
let uuidNumber = 0;
const requests = [];
let deniedEndpoint = '';
const restUrl = 'https://example.invalid/wp-json/sabri-network/v2/';
const responses = {
  'future/capabilities':{feature_count:24,items:[{id:'F17-FUT-01',label:'<img src=x onerror=alert(1)>',phase:'Advanced',status:'provider-gated'}]},
  'future/community-invites':{id:61,qr_payload:'sn-network://community-invite?token=secret-example'}
};
async function fetchStub(url, options) {
  const endpoint = String(url).replace(restUrl, '');
  requests.push({endpoint,options});
  if (endpoint === deniedEndpoint) return {ok:false,json:async()=>({message:'Approved provider unavailable — simulated refusal'})};
  const payload = endpoint === 'future/ai-assistant' ? {result:'<script>escape this text</script>'} : (responses[endpoint] || {id:12,items:[]});
  return {ok:true,json:async () => payload};
}
const window = {SN_FUTURE_CONFIG:{restUrl,nonce:'test-nonce'},crypto:{randomUUID:()=>'00000000-0000-4000-8000-'+String(++uuidNumber).padStart(12,'0')},confirm:()=>confirmResult};
const source = fs.readFileSync(path.join(__dirname,'../assets/js/future-superset.js'),'utf8');
vm.runInNewContext(source,{document,window,fetch:fetchStub,FormData:FakeFormData,Uint8Array,console},{filename:'future-superset.js'});

const run = async () => {
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(forms.size,31,'3 original + 4 initial + 12 Next + 8 lifecycle/privacy + 2 reminder + 1 team read + 1 template preview controls should be wired');
  assert.match(status.innerHTML,/24 advanced File 17 capabilities/);
  assert.ok(!status.innerHTML.includes('<img'),'capability names must be HTML-escaped');

  await forms.get('snf-team-inbox-read-form').submit({conversation_id:'8'});
  assert.equal(requests.at(-1).endpoint,'future/team-inbox/8','team manager must be able to read current version');
  await forms.get('snf-team-inbox-form').submit({conversation_id:'8',assignee_id:'11',status:'pending',enabled:'on',expected_version:'3'});
  assert.equal(requests.at(-1).endpoint,'future/team-inbox/8');
  assert.equal(JSON.parse(requests.at(-1).options.body).expected_version,3,'team assignment must carry last observed version');
  const beforeInventory = status.innerHTML;
  await forms.get('snf-smart-view-form').submit({name:'Unread cases',unread:'on',has_files:null,days:'30'});
  assert.equal(status.innerHTML,beforeInventory,'form success must not erase capability inventory');
  assert.ok(requests.some(r=>r.endpoint==='future/smart-views'));
  assert.ok(nodes.some(n=>n.id==='snf-feedback' && n.textContent.includes('Request accepted')),'feedback should be announced separately');

  const bulk = forms.get('snf-bulk-form');
  await bulk.submit({conversation_ids:'1,2,2 3',action:'archive',label:'',assignee_id:'',preview:'on'});
  const preview = requests.filter(r=>r.endpoint==='future/conversations/bulk').at(-1);
  assert.deepEqual(JSON.parse(preview.options.body).conversation_ids,[1,2,3]);
  assert.equal(JSON.parse(preview.options.body).preview,true);
  assert.equal(preview.options.headers['X-WP-Nonce'],'test-nonce');
  assert.match(preview.options.headers['Idempotency-Key'],/^[a-f0-9-]{36}$/);
  let count = requests.length;
  await bulk.submit({conversation_ids:'0,2',action:'archive',preview:'on'});
  assert.equal(requests.length,count,'invalid IDs must not reach the server');
  await bulk.submit({conversation_ids:'1,2',action:'archive',preview:null});
  assert.equal(requests.length,count,'executing batch must require affirmative confirmation');
  confirmResult = true;
  await bulk.submit({conversation_ids:'1,2',action:'archive',preview:null});
  assert.equal(JSON.parse(requests.at(-1).options.body).preview,false);

  count = requests.length;
  await forms.get('snf-ai-form').submit({conversation_id:'8',message_ids:'51,52',task:'summary',explicit_consent:null});
  assert.equal(requests.length,count,'AI selected content must not transmit without consent');
  await forms.get('snf-case-form').submit({conversation_id:'8',summary:'deidentified',consent_asserted:null});
  assert.equal(requests.length,count,'clinical case content must not transmit without consent');
  await forms.get('snf-ai-form').submit({conversation_id:'8',message_ids:'51,52,52',task:'summary',explicit_consent:'on'});
  assert.equal(requests.at(-1).endpoint,'future/ai-assistant');
  assert.deepEqual(JSON.parse(requests.at(-1).options.body).message_ids,[51,52]);
  await forms.get('snf-ai-form').submit({conversation_id:'8',message_ids:'51',task:'translate',target_locale:'ur',explicit_consent:'on'});
  assert.equal(JSON.parse(requests.at(-1).options.body).task,'translate','Translation must remain an explicit, opt-in task');
  assert.equal(JSON.parse(requests.at(-1).options.body).target_locale,'ur','Approved locale must be forwarded, not inferred');
  const readout=nodes.filter(n=>n.className==='snf-readout').at(-1);
  assert.ok(readout.textContent.includes('<script>escape this text</script>'),'response remains literal safe text');
  assert.equal(readout.innerHTML,'','untrusted provider content must never be injected as HTML');

  await forms.get('snf-invite-create-form').submit({space_id:'11',role:'observer',expires_in_hours:'12',max_uses:'1'});
  const invitation=nodes.filter(n=>n.tag==='textarea' && n.readOnly).at(-1);
  assert.equal(invitation.value,responses['future/community-invites'].qr_payload);
  assert.equal(invitation.attributes['aria-label'],'Private invitation token');
  assert.equal(status.innerHTML,beforeInventory,'invitation must not erase capability inventory');

  await forms.get('snf-semantic-consent-form').submit({enabled:'no'});
  assert.equal(requests.at(-1).endpoint,'future/semantic-search/consent','withdrawal must use canonical private consent route');
  assert.equal(JSON.parse(requests.at(-1).options.body).enabled,false,'withdrawal must be explicit false');
  await forms.get('snf-semantic-consent-form').submit({enabled:'yes'});
  assert.equal(JSON.parse(requests.at(-1).options.body).enabled,true,'enable must require explicit affirmative opt-in');
  await forms.get('snf-smart-results-form').submit({id:'31'});
  assert.equal(requests.at(-1).endpoint,'future/smart-views/31/results');
  await forms.get('snf-bulk-status-form').submit({id:'41'});
  assert.equal(requests.at(-1).endpoint,'future/conversations/bulk/41');
  confirmResult = false;
  count = requests.length;
  await forms.get('snf-revoke-record-form').submit({id:'23'});
  assert.equal(requests.length,count,'erasure must require explicit confirmation');
  confirmResult = true;
  await forms.get('snf-revoke-record-form').submit({id:'23'});
  assert.equal(requests.at(-1).endpoint,'future/records/23');
  assert.equal(requests.at(-1).options.method,'DELETE');
  await forms.get('snf-invite-revoke-form').submit({id:'61'});
  assert.equal(requests.at(-1).endpoint,'future/community-invites/61/revoke');
  await forms.get('snf-mentorship-end-form').submit({id:'77'});
  assert.equal(requests.at(-1).endpoint,'future/mentorships/77/end');

  deniedEndpoint = 'calls/4/lobby';
  count = requests.length;
  await forms.get('snf-lobby-form').submit({call_id:'4',user_id:'9',state:'admitted'});
  assert.equal(requests.length,count+1,'provider request may be attempted only once');
  assert.equal(nodes.find(node=>node.id==='snf-feedback').textContent,'Approved provider unavailable — simulated refusal','provider unavailability must fail visibly');
  assert.equal(nodes.find(node=>node.id==='snf-feedback').className,'snf-status snf-error');
  deniedEndpoint = '';
  await forms.get('snf-reminder-cancel-form').submit({id:'78',expected_version:'2'});
  assert.equal(requests.at(-1).endpoint,'future/reminders/78/cancel');
  assert.equal(JSON.parse(requests.at(-1).options.body).expected_version,2);
  const futureDate = new Date(Date.now()+3600000).toISOString();
  await forms.get('snf-reminder-reschedule-form').submit({id:'78',expected_version:'3',remind_at:futureDate});
  assert.equal(requests.at(-1).endpoint,'future/reminders/78/reschedule');
  assert.equal(JSON.parse(requests.at(-1).options.body).expected_version,3);
  assert.equal(JSON.parse(requests.at(-1).options.body).remind_at,futureDate);
  confirmResult = false;
  const deniedCount=requests.length;
  await forms.get('snf-reminder-cancel-form').submit({id:'79',expected_version:'1'});
  assert.equal(requests.length,deniedCount,'reminder cancellation requires explicit confirmation');
  await forms.get('snf-template-preview-form').submit({id:'91',recipient_name:'Ahmad',clinic_name:'Sabri Clinic',appointment_date:'2026-11-16'});
  assert.equal(requests.at(-1).endpoint,'future/templates/91/preview','template preview must go to its private owner-scoped endpoint');
  assert.equal(JSON.parse(requests.at(-1).options.body).variables.recipient_name,'Ahmad','safe template variables are explicit');
  assert.ok(!requests.some(item=>item.endpoint.includes('/messages/send')),'template preview must never auto-send messages');
  for (const form of forms.values()) assert.equal(form.button.disabled,false,'button must re-enable after completion/refusal: '+form.id);
  process.stdout.write('File-17 V12.3 advanced workspace runtime contracts: PASS (31 forms, consent, batch, escaping, feedback)\n');
};
run().catch(error=>{console.error(error);process.exitCode=1;});
