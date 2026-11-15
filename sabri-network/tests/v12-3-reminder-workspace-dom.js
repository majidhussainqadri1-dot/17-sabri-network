'use strict';
/*
 * V12.3 F17-FUT-07 — executable DOM-lite user journey.
 * No network or browser dependencies; does not replace real WCAG/device E2E.
 * Run under both UTC and America/New_York for local-time DST adversarial checks.
 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.resolve(__dirname, '../assets/js/future-superset.js'), 'utf8');
let assertions = 0;
function check(condition, detail) { assert.ok(condition, detail); ++assertions; }

class Element {
  constructor(tag) {
    this.tagName = tag;
    this.children = [];
    this.events = {};
    this.parent = null;
    this._text = '';
    this.className = '';
    this.attributes = {};
    this.disabled = false;
    this.value = '';
  }
  set textContent(value) { this._text = String(value); this.children = []; }
  get textContent() { return this._text + this.children.map(c => c.textContent).join(''); }
  appendChild(child) { child.parent = this; this.children.push(child); return child; }
  append(...children) { children.forEach(child => this.appendChild(child)); }
  replaceChildren(...children) { this._text = ''; this.children = []; this.append(...children); }
  addEventListener(type, fn) { this.events[type] = fn; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  focus() { this.focused = true; }
  reset() { this.fields = {}; }
  remove() {
    if (this.parent) this.parent.children = this.parent.children.filter(x => x !== this);
    this.parent = null;
  }
  querySelector(selector) {
    const found = child => {
      if (selector === 'form') return child.tagName === 'form';
      if (selector === 'button[type="submit"]') return child.tagName === 'button' && child.type === 'submit';
      return false;
    };
    for (const child of this.children) {
      if (found(child)) return child;
      const nested = child.querySelector(selector);
      if (nested) return nested;
    }
    return null;
  }
}
class FakeFormData {
  constructor(form) { this.fields = {...(form.fields || {})}; }
  get(key) { return this.fields[key] ?? null; }
  has(key) { return Object.hasOwn(this.fields, key); }
  set(key, val) { this.fields[key] = val; }
}
const elements = {};
['sn-future-workspace','snf-status','snf-reminders','snf-refresh-reminders',
 'snf-lock-form','snf-reminder-form','snf-template-form'].forEach(id => {
   elements[id] = new Element(id.includes('form') ? 'form' : 'div');
 });
const document = {
  getElementById(id) { return elements[id] || null; },
  createElement(tag) { return new Element(tag); }
};
const formatLocal = date => [
  date.getFullYear(), String(date.getMonth()+1).padStart(2,'0'),
  String(date.getDate()).padStart(2,'0')
].join('-') + 'T' + [
  String(date.getHours()).padStart(2,'0'),
  String(date.getMinutes()).padStart(2,'0')
].join(':');
const utcSQL = timestamp => new Date(timestamp).toISOString().slice(0,19).replace('T',' ');
const records = [{
  id: 31, version: 3, scope_id: 42, state: 'active',
  expires_at: utcSQL(Date.now()+7200000), data: {label: 'Professional follow-up'}
}];
const requests = [];
const createByKey = new Map();
let lostResponseAfterCreate = false;
let failNextMutation = false;
let keyCounter = 0;
async function fetch(url, options = {}) {
  const resource = String(url).split('/v2/')[1] || '';
  const method = String(options.method || 'GET').toUpperCase();
  const body = options.body ? JSON.parse(options.body) : {};
  requests.push({resource, method, body, headers: options.headers});
  const response = (data, status = 200) => ({
    ok: status < 400, status, json: async () => data
  });
  if (resource === 'future/capabilities') return response({feature_count:24,items:[]});
  if (resource === 'future/reminders' && method === 'GET') return response({items:records.map(x=>({...x}))});
  if (resource === 'future/reminders' && method === 'POST') {
    if (!body.client_id) return response({message:'Client ID is required'},400);
    if (!(body.conversation_id > 0) || !body.remind_at?.endsWith('Z')) return response({message:'Invalid scope or instant'},400);
    if (createByKey.has(body.client_id)) return response({
      id:createByKey.get(body.client_id),version:1
    },201);
    const created = {
      id:31+records.length,version:1,scope_id:body.conversation_id,state:'active',
      expires_at:utcSQL(body.remind_at),data:{label:body.label}
    };
    records.push(created);
    createByKey.set(body.client_id,created.id);
    if(lostResponseAfterCreate){
      lostResponseAfterCreate=false;
      throw new Error('Simulated network response loss after server commit');
    }
    return response({id:created.id,version:1},201);
  }
  const match = resource.match(/^future\/reminders\/(\d+)$/);
  if (match && ['POST','DELETE'].includes(method)) {
    const row = records.find(x=>x.id === Number(match[1]));
    if (failNextMutation) {failNextMutation = false;return response({message:'Permission changed'},409);}
    if (!row || row.version !== body.expected_version) return response({message:'Stale version'},409);
    if (method === 'POST') { row.expires_at = utcSQL(body.remind_at); row.version++;return response({id:row.id,version:row.version}); }
    row.version++;row.state = 'cancelled';return response({id:row.id,version:row.version,state:row.state});
  }
  return response({message:'Unknown path'},404);
}

const window = {SN_FUTURE_CONFIG:{restUrl:'https://unit.test/wp-json/sabri-network/v2/',nonce:'test-nonce'},
  crypto:{randomUUID:()=>`456789ab-1234-4567-8123-${String(++keyCounter).padStart(12,'0')}`}};
vm.runInNewContext(source, {document, window, fetch, FormData:FakeFormData, Date, Uint8Array, console});
const tick = async () => { await new Promise(resolve => setImmediate(resolve)); };
function findByText(root, exact) {
  if (root.textContent === exact && root.tagName === 'button') return root;
  for (const child of root.children) {
    const found = findByText(child, exact);
    if (found) return found;
  }
  return null;
}
function listText() {return elements['snf-reminders'].textContent;}
function firstCard() {
  const container = elements['snf-reminders'].children[0];
  return container?.children[0];
}
async function submit(form) {
  const result = form.events.submit({preventDefault(){}});
  if (result && typeof result.then === 'function') await result;
  await tick();
}
(async () => {
  await tick();
  check(requests.some(r=>r.resource==='future/reminders'&&r.method==='GET'),
    'Initial mounted workspace fetches the canonical reminder list');
  check(listText().includes('Professional follow-up'), 'Existing user label is visible');
  check(listText().includes('Conversation #42'), 'Owned conversation context is visible');
  check(findByText(firstCard(),'Reschedule') && findByText(firstCard(),'Cancel'),
    'Versioned reminder provides reschedule and cancel controls');

  const reschedule = findByText(firstCard(),'Reschedule');
  reschedule.events.click();
  const form = firstCard().querySelector('form');
  check(form && form.children[0].children[0].tagName==='input',
    'Reschedule reveals a keyboard-operable datetime-local input');
  form.children[0].children[0].value = formatLocal(new Date(Date.now()+10800000));
  await submit(form);
  check(records[0].version===4,'Reschedule commits a versioned state update');
  const post = requests.find(r=>r.resource==='future/reminders/31' && r.method==='POST');
  check(post?.body.expected_version===3 && post?.body.remind_at.endsWith('Z'),
    'Reschedule sends client-reviewed version and unambiguous UTC instant');
  check(listText().includes('Professional follow-up'),
    'Reschedule refetches canonical state after mutation');

  failNextMutation = true;
  const cancel = findByText(firstCard(),'Cancel');
  await cancel.events.click();
  check(records[0].state==='active','Denied cancellation leaves canonical reminder active');
  check(elements['snf-status'].textContent==='Permission changed',
    'Mid-flight cancellation error is announced, not acknowledged as success');

  await findByText(firstCard(),'Cancel').events.click();
  check(records[0].state==='cancelled','Current-version cancellation changes canonical state');
  const deletion = requests.filter(r=>r.resource==='future/reminders/31'&&r.method==='DELETE').slice(-1)[0];
  check(deletion?.body.expected_version===4,'Cancel carries the latest canonical version');
  check(!findByText(firstCard(),'Cancel'),
    'Terminal reminder no longer exposes unsafe mutation buttons');

  elements['snf-reminder-form'].fields = {
    conversation_id:'42', label:'Second reminder',
    remind_at:formatLocal(new Date(Date.now()+14400000))
  };
  await submit(elements['snf-reminder-form']);
  check(records.length===2&&records[1].version===1,'Creation persists a separate caller-owned reminder');
  check(listText().includes('Second reminder'),'Creation refreshes reminder list');
  check(elements['snf-status'].textContent === 'Saved successfully.',
    'Successful creation surfaces success and resets the submitted form');

  const before = requests.filter(x=>x.resource==='future/reminders'&&x.method==='POST').length;
  if (process.env.TZ==='America/New_York') {
    elements['snf-reminder-form'].fields = {
      conversation_id:'42',label:'DST gap',remind_at:'2027-03-14T02:30'
    };
    await submit(elements['snf-reminder-form']);
    check(requests.filter(x=>x.resource==='future/reminders'&&x.method==='POST').length===before,
      'Spring DST gap is rejected client-side without sending a request');
    check(elements['snf-status'].textContent.includes('does not exist'),
      'Nonexistent DST time receives a plain-language error');
    elements['snf-reminder-form'].fields = {
      conversation_id:'42',label:'DST overlap',remind_at:'2027-11-07T01:30'
    };
    await submit(elements['snf-reminder-form']);
    check(requests.filter(x=>x.resource==='future/reminders'&&x.method==='POST').length===before,
      'Repeated fall-back wall clock is rejected rather than silently choosing an offset');
    check(elements['snf-status'].textContent.includes('occurs twice'),
      'Ambiguous DST time produces a clear client error');
  }

  const retryFields = {
    conversation_id:'42', label:'Lost-response retry',
    remind_at:formatLocal(new Date(Date.now()+21600000))
  };
  const creationForm = elements['snf-reminder-form'];
  creationForm.fields = {...retryFields};
  const countBeforeLoss = records.length;
  lostResponseAfterCreate=true;
  await submit(creationForm);
  check(records.length === countBeforeLoss+1,
    'Simulated server commit precedes a lost HTTP response');
  check(elements['snf-status'].textContent.includes('Simulated network response loss'),
    'Ambiguous network failure is surfaced rather than falsely acknowledged');
  const ambiguous = requests.filter(x=>x.resource==='future/reminders'&&x.method==='POST').slice(-1)[0];
  await submit(creationForm);
  const replay = requests.filter(x=>x.resource==='future/reminders'&&x.method==='POST').slice(-1)[0];
  check(ambiguous.body.client_id === replay.body.client_id,
    'Unchanged form retry preserves the exact caller-owned idempotency key');
  check(records.length === countBeforeLoss+1,
    'Replayed request after lost response cannot create a duplicate reminder');
  check(elements['snf-status'].textContent==='Saved successfully.',
    'A reconciled retry produces a genuine success notification');
  creationForm.fields = {...retryFields};
  await submit(creationForm);
  const separatelyCreated = requests.filter(x=>x.resource==='future/reminders'&&x.method==='POST').slice(-1)[0];
  check(separatelyCreated.body.client_id !== replay.body.client_id,
    'A separate intentional creation after confirmed success gets a new key');
  check(records.length === countBeforeLoss+2,
    'The client can intentionally create the same reminder again after success');

  records.push({
    id: 90, version: 0, scope_id: 42, state: 'active',
    expires_at: utcSQL(Date.now() + 7200000),
    data: {label: '<svg onload=alert(1)>Private display test'}
  });
  await elements['snf-refresh-reminders'].events.click();
  const rendered = elements['snf-reminders'].children[0].children;
  const legacy = rendered[rendered.length - 1];
  check(legacy.textContent.includes('<svg onload=alert(1)>Private display test'),
    'User-controlled label is displayed literally via DOM textContent, never executed');
  check(findByText(legacy,'Cancel')?.disabled===true
     &&findByText(legacy,'Reschedule')?.disabled===true,
    'Record without a valid current version cannot be mutated from the UI');
  check(requests.filter(x=>x.resource==='future/reminders'&&x.method==='GET').length >= 4,
    'Refresh control explicitly reloads canonical reminder state');
  check(!elements['snf-refresh-reminders'].disabled,'Refresh control is restored after completion');
  check(requests.filter(x=>x.method!=='GET').every(x=>x.headers['X-WP-Nonce']==='test-nonce'),
    'Mutations are sent with existing WordPress nonce');
  console.log(`V12.3 reminder UI DOM journey (${process.env.TZ||'host TZ'}): ${assertions} PASS, 0 FAIL`);
})().catch(error=>{console.error(error);process.exitCode=1;});
