'use strict';
/** V12.3 runtime DOM-lite: effective FUT-05/06 reasoned CAS, FUT-09 privacy, FUT-11 saved views. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.resolve(__dirname,'../assets/js/future-superset.js'),'utf8');
let n = 0;
const check = (condition,message) => { assert.ok(condition,message); n++; };
class Element {
  constructor(tag='div') {this.tagName=tag;this.events={};this.children=[];this.disabled=false;this.value='';this._text='';}
  set textContent(value){this._text=String(value);this.children=[];}
  get textContent(){return this._text+this.children.map(x=>x.textContent).join('');}
  addEventListener(name,fn){this.events[name]=fn;}
  reset(){this.fields={};}
  appendChild(child){this.children.push(child);return child;}
  append(...items){items.forEach(x=>this.appendChild(x));}
  replaceChildren(...items){this._text='';this.children=[];this.append(...items);}
  setAttribute(name,value){this[name]=String(value);}
  focus(){this.focused=true;}
  querySelector(selector){return null;}
}
class FakeFormData {
  constructor(form){this.values={...(form.fields||{})};}
  get(name){return this.values[name]??null;}
  has(name){return Object.hasOwn(this.values,name);}
  set(name,value){this.values[name]=value;}
}
const ids=['sn-future-workspace','snf-status','snf-reminders','snf-refresh-reminders',
 'snf-lock-form','snf-reminder-form','snf-template-form','snf-team-settings-form',
 'snf-handoff-form','snf-view-form','snf-history-form','snf-history-output'];
const elements=Object.fromEntries(ids.map(id=>[id,new Element(id.endsWith('-form')?'form':'div')]));
const document={getElementById:id=>elements[id]||null,createElement:tag=>new Element(tag)};
const requests=[];let teamVersion=3;let assignee=8;let teamStatus='open';let counter=0;
const response=(data,status=200)=>({ok:status<400,status,json:async()=>data});
async function fetch(url,options={}) {
  const resource=String(url).split('/v2/')[1]||'';
  const method=(options.method||'GET').toUpperCase();
  const body=options.body?JSON.parse(options.body):{};
  requests.push({resource,method,body,headers:options.headers});
  if(resource==='future/capabilities')return response({feature_count:24,items:[]});
  if(resource==='future/reminders'&&method==='GET')return response({items:[]});
  if(resource==='future/team-inbox/42'&&method==='GET')
    return response({conversation_id:42,version:teamVersion,team_inbox:{assignee_id:assignee,status:teamStatus,enabled:true}});
  if(resource==='future/team-inbox/42'&&method==='POST'){
    if(Object.hasOwn(body,'assignee_id'))return response({message:'Use the dedicated handoff'},409);
    if(body.expected_version!==teamVersion)return response({message:'Stale team version'},409);
    teamStatus=body.status;teamVersion++;return response({version:teamVersion,team_inbox:{status:teamStatus}});
  }
  if(resource==='future/team-inbox/42/handoff'&&method==='POST'){
    if(body.expected_version!==teamVersion)return response({message:'Handoff stale'},409);
    if(!(body.assignee_id>0)||!String(body.reason||'').trim())return response({message:'Reasoned handoff required'},400);
    assignee=body.assignee_id;teamVersion++;
    return response({version:teamVersion,assignee_id:assignee});
  }
  if(resource==='future/smart-views'&&method==='POST')return response({id:71,name:body.name},201);
  if(resource==='future/messages/55/versions'&&method==='GET')
    return response({message_id:55,versions:[{revision:1,created_at:'2026-11-15',body:'<img src=x onerror=alert(1)>'}]});
  if(resource==='future/messages/56/versions'&&method==='GET')
    return response({message:'Not authorized'},403);
  return response({message:'Unexpected API path: '+resource},404);
}
const window={SN_FUTURE_CONFIG:{restUrl:'https://example.test/wp-json/sabri-network/v2/',nonce:'nonce'},
  crypto:{randomUUID:()=>('99999999-2222-4222-8222-'+String(++counter).padStart(12,'0'))}};
vm.runInNewContext(source,{document,window,fetch,FormData:FakeFormData,Date,Uint8Array,console});
const tick=async()=>new Promise(resolve=>setImmediate(resolve));
async function submit(id,fields){
  const form=elements[id];form.fields=fields;
  check(typeof form.events.submit==='function','Form handler is wired: '+id);
  await form.events.submit({preventDefault(){}});
  await tick();
}
(async()=>{
  await tick();
  await submit('snf-team-settings-form',{conversation_id:'42',enabled:'on',status:'pending'});
  const settings=requests.find(x=>x.resource==='future/team-inbox/42'&&x.method==='POST');
  check(settings&&settings.body.expected_version===3,'Settings sends real current version');
  check(!Object.hasOwn(settings.body,'assignee_id'),'Settings cannot bypass dedicated assignment control');
  check(teamVersion===4&&teamStatus==='pending','Server accepted settings-only mutation');

  await submit('snf-handoff-form',{conversation_id:'42',assignee_id:'9',reason:'Duty rotation'});
  const handoff=requests.find(x=>x.resource==='future/team-inbox/42/handoff');
  check(handoff&&handoff.method==='POST','Handoff uses dedicated REST endpoint');
  check(handoff.body.expected_version===4&&handoff.body.assignee_id===9,'Handoff uses freshly read version and target');
  check(handoff.body.reason==='Duty rotation','Handoff transmits an explicit reason');
  check(teamVersion===5&&assignee===9,'Handoff applies exactly one target change');

  await submit('snf-view-form',{name:'My unread files',unread:'on',has_files:'on',days:'30'});
  const view=requests.find(x=>x.resource==='future/smart-views'&&x.method==='POST');
  check(view&&view.body.criteria.unread&&view.body.criteria.has_files,'Private view uses allowlisted filters');
  check(view.body.criteria.days===30&&view.body.name==='My unread files','Saved-view metadata is bounded');

  await submit('snf-history-form',{message_id:'55'});
  const text=elements['snf-history-output'].textContent;
  check(text.includes('<img src=x onerror=alert(1)>'),'Authorized historical body is shown literally');
  check(!elements['snf-history-output'].innerHTML,'History never renders untrusted HTML');
  check(elements['snf-status'].textContent==='Authorized history loaded.','Read-only success is not claimed as a saved mutation');

  await submit('snf-history-form',{message_id:'56'});
  check(elements['snf-history-output'].textContent==='History unavailable.','Denied history clears previous private body');
  check(elements['snf-status'].textContent==='Not authorized','Permission failure is announced, not falsely accepted');
  check(requests.filter(x=>x.method!=='GET').every(x=>x.headers['X-WP-Nonce']==='nonce'&&x.headers['Idempotency-Key']),
    'All workspace mutations retain authentication nonce and retry identity');
  console.log('V12.3 reasoned workspace DOM journey: '+n+' PASS, 0 FAIL');
})().catch(e=>{console.error(e);process.exitCode=1;});
