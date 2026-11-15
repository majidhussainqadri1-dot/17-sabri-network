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
  // Keep the capability inventory intact when an action returns; report outcomes separately.
  const feedback = document.createElement('div');
  feedback.id = 'snf-feedback';
  feedback.className = 'snf-status';
  feedback.setAttribute('role', 'status');
  feedback.setAttribute('aria-live', 'polite');
  feedback.hidden = true;
  status.insertAdjacentElement('afterend', feedback);
  const inviteOutput = document.createElement('div');
  inviteOutput.className = 'snf-status';
  inviteOutput.hidden = true;
  feedback.insertAdjacentElement('afterend', inviteOutput);
  const announce = (message, error = false) => {
    feedback.className = error ? 'snf-status snf-error' : 'snf-status';
    feedback.textContent = String(message);
    feedback.hidden = false;
  };
  const showInvitation = payload => {
    inviteOutput.replaceChildren();
    inviteOutput.hidden = false;
    const label = document.createElement('label');
    label.textContent = 'Private invitation: share this single-use-or-limited-use link only with the intended participant.';
    const field = document.createElement('textarea');
    field.readOnly = true;
    field.rows = 3;
    field.setAttribute('aria-label', 'Private invitation token');
    field.value = String(payload);
    label.append(field);
    inviteOutput.append(label);
  };
  const showReadout = (title, payload) => {
    inviteOutput.replaceChildren();
    const heading = document.createElement('strong');
    heading.textContent = String(title);
    const pre = document.createElement('pre');
    pre.className = 'snf-readout';
    pre.textContent = JSON.stringify(payload, null, 2).slice(0, 16000);
    inviteOutput.append(heading, pre);
    inviteOutput.hidden = false;
  };
  const featureList = items => `<div class="snf-feature-list">${items.map(item => `<article class="snf-feature"><div><strong>${esc(item.id)} — ${esc(item.label)}</strong><small>${esc(item.phase)}${item.provider ? ` · provider: ${esc(item.provider)}` : ''}</small></div><span class="snf-badge snf-${esc(item.status)}">${esc(item.status)}</span></article>`).join('')}</div>`;
  async function loadCapabilities() { try { const data = await api('future/capabilities'); status.innerHTML = `<strong>${Number(data.feature_count || 0)} advanced File 17 capabilities</strong>${featureList(data.items || [])}`; status.className='snf-status'; } catch (error) { announce(error.message,true); } }
  const bind = (id, handler) => {
    const form = document.getElementById(id);
    if (!form) return;
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]') || form.querySelector('button');
      if (button?.disabled) return;
      if (button) button.disabled = true;
      inviteOutput.hidden = true;
      inviteOutput.replaceChildren();
      try {
        const result = await handler(new FormData(form));
        const message = result?.job_id
          ? `Batch ${Number(result.job_id)} queued (processed only when the worker runs); check the job status before relying on it.`
          : (result?.status_message || 'Request accepted. Final processing or provider confirmation may still be required.');
        announce(message);
        if (typeof result?.qr_payload === 'string') showInvitation(result.qr_payload);
        if (result?.workspace_readout) showReadout(result.workspace_title || 'Authorized response', result.workspace_readout);
        if (id !== 'snf-lock-form' && id !== 'snf-bulk-form') form.reset();
      } catch (error) {
        announce(error?.message || 'The request failed.', true);
      } finally {
        if (button) button.disabled = false;
      }
    });
  };
  bind('snf-lock-form', data => api(`future/conversation-locks/${Number(data.get('conversation_id'))}`, {method:'POST', body:{enabled:data.get('enabled')==='on'}}));
  bind('snf-reminder-form', data => { const local=String(data.get('remind_at')||'');const parsed=local?new Date(local):null;return api('future/reminders',{method:'POST',body:{conversation_id:Number(data.get('conversation_id')),remind_at:parsed&&!Number.isNaN(parsed.getTime())?parsed.toISOString():local,label:String(data.get('label')||'')}}); });
  bind('snf-template-form', data => api('future/templates',{method:'POST',body:{title:String(data.get('title')||''),body:String(data.get('body')||'')}}));
  // V12.3: additional governed UI actions, using only canonical File-17 APIs.
  const advancedGrid = root.querySelector('.snf-grid');
  const extraControls = [
    '<form id="snf-smart-view-form" class="snf-card"><h3>Saved private view</h3><label>View name<input name="name" maxlength="100" required></label><label><input name="unread" type="checkbox"> Unread only</label><label><input name="has_files" type="checkbox"> With files</label><label>Last number of days<input name="days" type="number" min="1" max="365" value="30" required></label><button type="submit">Save private view</button></form>',
    '<form id="snf-team-inbox-form" class="snf-card"><h3>Authorized team inbox</h3><p>Only conversation managers may assign an active participant.</p><label>Conversation ID<input name="conversation_id" type="number" min="1" required></label><label>Assignee user ID<input name="assignee_id" type="number" min="0" required></label><label>Queue status<select name="status"><option value="open">Open</option><option value="pending">Pending</option><option value="resolved">Resolved</option></select></label><label>Current team record version (read first)<input name="expected_version" type="number" min="0" value="0" required></label><label><input name="enabled" type="checkbox" checked> Enable team inbox</label><button type="submit">Save team assignment</button></form>',
    '<form id="snf-citation-form" class="snf-card"><h3>Scholarly citation card</h3><p>Link a File 06 or File 12 canonical source without copying protected content.</p><label>Conversation ID<input name="conversation_id" type="number" min="1" required></label><label>Source<select name="source_owner"><option value="file-06">Encyclopedia</option><option value="file-12">PDF Library</option></select></label><label>Canonical source ID<input name="canonical_id" maxlength="191" required></label><label>Source title<input name="title" maxlength="300" required></label><label>Page or locator<input name="locator" maxlength="120"></label><button type="submit">Add citation reference</button></form>',
    '<form id="snf-mentorship-form" class="snf-card"><h3>Mentorship invitation</h3><p>Eligibility, blocks and guardian approval are checked by the server.</p><label>Student user ID<input name="student_id" type="number" min="1" required></label><label>Shared conversation ID (optional)<input name="conversation_id" type="number" min="0" value="0"></label><button type="submit">Request mentorship</button></form>'
  ];
  if (advancedGrid) advancedGrid.insertAdjacentHTML('beforeend', extraControls.join(''));
  bind('snf-smart-view-form', data => api('future/smart-views', {method:'POST', body:{name:String(data.get('name') || ''), criteria:{unread:data.get('unread')==='on', has_files:data.get('has_files')==='on', days:Number(data.get('days') || 30)}}}));
  bind('snf-team-inbox-form', data => api(`future/team-inbox/${Number(data.get('conversation_id'))}`, {method:'POST', body:{assignee_id:Number(data.get('assignee_id') || 0), status:String(data.get('status') || 'open'), enabled:data.get('enabled')==='on', expected_version:Number(data.get('expected_version'))}}));
  bind('snf-citation-form', data => api('future/citations', {method:'POST', body:{conversation_id:Number(data.get('conversation_id')), source_owner:String(data.get('source_owner') || ''), canonical_id:String(data.get('canonical_id') || ''), title:String(data.get('title') || ''), locator:String(data.get('locator') || '')}}));
  bind('snf-mentorship-form', data => api('future/mentorships', {method:'POST', body:{student_id:Number(data.get('student_id')), conversation_id:Number(data.get('conversation_id') || 0)}}));
  // V12.3 Next/SCALE/Advanced: no duplicate data truth; use current authoritative REST handlers.
  const nextJourneyForms = [
    "<details id=\"snf-more\" class=\"snf-more\"><summary>Further File 17 actions: version history, bulk operations, invitations, supervised communication and provider-gated services</summary><p>Actions require current account permissions. A provider-gated capability can return unavailable until independently approved; this interface does not grant access.</p><div class=\"snf-grid\">",
    "<form id=\"snf-revisions-form\" class=\"snf-card\"><h3>Authorized message revisions</h3><label>Message ID<input name=\"message_id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Read permitted versions</button></form>",
    "<form id=\"snf-bulk-form\" class=\"snf-card\"><h3>Bounded conversation batch</h3><label>Conversation IDs (comma-separated, up to 100)<textarea name=\"conversation_ids\" maxlength=\"1100\" required></textarea></label><label>Action<select name=\"action\"><option value=\"archive\">Archive</option><option value=\"unarchive\">Restore archive</option><option value=\"mute\">Mute</option><option value=\"unmute\">Unmute</option><option value=\"mark_read\">Mark read</option><option value=\"mark_unread\">Mark unread</option><option value=\"label\">Label</option><option value=\"assign\">Assign</option><option value=\"export_request\">Request export</option></select></label><label>Optional label<input name=\"label\" maxlength=\"80\"></label><label>Optional assignee ID<input name=\"assignee_id\" type=\"number\" min=\"0\"></label><label><input type=\"checkbox\" name=\"preview\" checked> Preview only (recommended)</label><button type=\"submit\">Submit bounded batch</button></form>",
    "<form id=\"snf-invite-create-form\" class=\"snf-card\"><h3>Expiring community invitation</h3><label>Community ID<input name=\"space_id\" type=\"number\" min=\"1\" required></label><label>Invite role<select name=\"role\"><option value=\"member\">Member</option><option value=\"observer\">Observer</option></select></label><label>Expires in hours<input name=\"expires_in_hours\" type=\"number\" min=\"1\" max=\"168\" value=\"24\" required></label><label>Maximum uses<input name=\"max_uses\" type=\"number\" min=\"1\" max=\"100\" value=\"1\" required></label><button type=\"submit\">Create limited invitation</button></form>",
    "<form id=\"snf-invite-redeem-form\" class=\"snf-card\"><h3>Redeem community invitation</h3><p>Never post an invitation token publicly. Normal identity, age and guardian checks still apply.</p><label>Private signed token<textarea name=\"token\" maxlength=\"3000\" required></textarea></label><button type=\"submit\">Redeem my invitation</button></form>",
    "<form id=\"snf-temp-member-form\" class=\"snf-card\"><h3>Time-limited membership</h3><label>Community ID<input name=\"space_id\" type=\"number\" min=\"1\" required></label><label>Eligible account ID<input name=\"user_id\" type=\"number\" min=\"1\" required></label><label>Role<select name=\"role\"><option value=\"observer\">Observer</option><option value=\"member\">Member</option></select></label><label>Expires at (your local time)<input name=\"expires_at\" type=\"datetime-local\" required></label><button type=\"submit\">Grant scoped access</button></form>",
    "<form id=\"snf-mentorship-decision-form\" class=\"snf-card\"><h3>Respond to mentorship invitation</h3><label>Invitation ID<input name=\"id\" type=\"number\" min=\"1\" required></label><label>Decision<select name=\"decision\"><option value=\"decline\">Decline</option><option value=\"accept\">Accept</option></select></label><button type=\"submit\">Submit my decision</button></form>",
    "<form id=\"snf-case-form\" class=\"snf-card\"><h3>De-identified professional discussion</h3><p>Verified professional approval and patient consent are required. Do not enter names, phone numbers, addresses, identifiers or identifying photos. This is not a patient chart.</p><label>Authorized conversation ID<input name=\"conversation_id\" type=\"number\" min=\"1\" required></label><label>Age band<input name=\"age_band\" maxlength=\"40\"></label><label>Sex (if relevant)<select name=\"sex\"><option value=\"unspecified\">Unspecified</option><option value=\"female\">Female</option><option value=\"male\">Male</option></select></label><label>De-identified summary<textarea name=\"summary\" maxlength=\"12000\" required></textarea></label><label><input type=\"checkbox\" name=\"consent_asserted\" required> I confirm applicable consent and removal of direct identifiers</label><button type=\"submit\">Submit for authorized discussion</button></form>",
    "<form id=\"snf-handraise-form\" class=\"snf-card\"><h3>Raise or lower my hand</h3><label>Current call ID<input name=\"call_id\" type=\"number\" min=\"1\" required></label><label><input name=\"raised\" type=\"checkbox\" checked> Hand raised</label><button type=\"submit\">Update hand status</button></form>",
    "<form id=\"snf-lobby-form\" class=\"snf-card\"><h3>Call host: waiting-room moderation</h3><p>Provider-dependent. Host authority, membership, age, blocks and current call state are checked on the server.</p><label>Call ID<input name=\"call_id\" type=\"number\" min=\"1\" required></label><label>Participant account ID<input name=\"user_id\" type=\"number\" min=\"1\" required></label><label>Action<select name=\"state\"><option value=\"waiting\">Waiting</option><option value=\"admitted\">Admit</option><option value=\"rejected\">Reject</option></select></label><button type=\"submit\">Update waiting room</button></form>",
    "<form id=\"snf-ai-form\" class=\"snf-card\"><h3>AI helper — opt-in, File 16 approved provider only</h3><p>Selected message context may be sent to an approved AI adapter only after policy checks and your explicit consent. Do not include patient identifiers without lawful authorization.</p><label>Conversation ID<input name=\"conversation_id\" type=\"number\" min=\"1\" required></label><label>Selected message IDs, comma-separated<input name=\"message_ids\" maxlength=\"400\" required></label><label>Approved task<select name=\"task\"><option value=\"summary\">Summary</option><option value=\"draft\">Draft</option><option value=\"action_items\">Action items</option><option value=\"explain\">Explain</option><option value=\"translate\">Translate (approved locale)</option></select></label><label>Translation target locale<input name=\"target_locale\" maxlength=\"16\" placeholder=\"ur, ar, en-us\"></label><label><input name=\"explicit_consent\" type=\"checkbox\" required> I explicitly consent to this request and selected context</label><button type=\"submit\">Request governed AI assistance</button></form>",
    "<form id=\"snf-semantic-form\" class=\"snf-card\"><h3>Private semantic search — separately opt-in</h3><p>Requires active per-user consent and an approved private-index adapter. Never sent to public File 26 search.</p><label>Conversation ID<input name=\"conversation_id\" type=\"number\" min=\"1\" required></label><label>Private search text<input name=\"query\" maxlength=\"500\" minlength=\"2\" required></label><button type=\"submit\">Search authorized conversation</button></form>",
    "<form id=\"snf-safety-form\" class=\"snf-card\"><h3>Device safety-number comparison</h3><p>Only a cryptographic device-binding comparison. It is not proof that audited E2EE is available.</p><label>Other participant account ID<input name=\"user_id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Show safety comparison</button></form>",
    "<form id=\"snf-my-records-form\" class=\"snf-card\"><h3>My governed workspace items</h3><p>Only lists authorized by the current account are returned.</p><label>Item collection<select name=\"type\"><option value=\"future/reminders\">Reminders</option><option value=\"future/templates\">Saved replies</option><option value=\"future/smart-views\">Private views</option><option value=\"future/mentorships\">Mentorships</option><option value=\"future/interop\">Approved bridges</option></select></label><button type=\"submit\">Read my items</button></form>",
    "<form id=\"snf-smart-results-form\" class=\"snf-card\"><h3>Run my private saved view</h3><label>Saved view ID<input name=\"id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Run with current permissions</button></form>",
    "<form id=\"snf-bulk-status-form\" class=\"snf-card\"><h3>Check bounded batch progress</h3><label>Batch job ID<input name=\"id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Check job</button></form>",
    "<form id=\"snf-bulk-cancel-form\" class=\"snf-card\"><h3>Cancel my pending batch</h3><p>Cancellation cannot undo already committed item operations.</p><label>Batch job ID<input name=\"id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Cancel pending job</button></form>",
    "<form id=\"snf-semantic-consent-form\" class=\"snf-card\"><h3>Private semantic index consent</h3><p>Independent opt-in. Withdrawal requests private index deletion; the response reports whether purge confirmation is still pending.</p><label>Consent<select name=\"enabled\"><option value=\"no\">Withdraw and request purge</option><option value=\"yes\">Enable private search</option></select></label><button type=\"submit\">Update private search consent</button></form>",
    "<form id=\"snf-revoke-record-form\" class=\"snf-card\"><h3>Erase my advanced private record</h3><p>Only server-authorized record types may be deleted. This action may be irreversible.</p><label>Record ID<input name=\"id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Request record erasure</button></form>",
    "<form id=\"snf-invite-revoke-form\" class=\"snf-card\"><h3>Revoke a community invitation</h3><label>My invitation record ID<input name=\"id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Revoke invitation</button></form>",
    "<form id=\"snf-mentorship-end-form\" class=\"snf-card\"><h3>End a mentorship</h3><label>Mentorship record ID<input name=\"id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">End mentorship safely</button></form>",
    "<form id=\"snf-reminder-cancel-form\" class=\"snf-card\"><h3>Cancel my pending reminder</h3><p>Read the current record version from My reminders before changing it.</p><label>Reminder ID<input name=\"id\" type=\"number\" min=\"1\" required></label><label>Current version<input name=\"expected_version\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Cancel my reminder</button></form>",
    "<form id=\"snf-reminder-reschedule-form\" class=\"snf-card\"><h3>Reschedule my reminder</h3><p>Only the current owner and current message/conversation member may reschedule it.</p><label>Reminder ID<input name=\"id\" type=\"number\" min=\"1\" required></label><label>Current version<input name=\"expected_version\" type=\"number\" min=\"1\" required></label><label>New reminder time (local)<input name=\"remind_at\" type=\"datetime-local\" required></label><button type=\"submit\">Change reminder time</button></form>",
    "<form id=\"snf-team-inbox-read-form\" class=\"snf-card\"><h3>Read current team assignment</h3><p>Obtain the current version, then enter it above before changing any assignment.</p><label>Conversation ID<input name=\"conversation_id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Read team state and version</button></form>",
    "<form id=\"snf-template-preview-form\" class=\"snf-card\"><h3>Preview my saved reply — never auto-send</h3><p>Only three safe variables are supported: recipient_name, clinic_name and appointment_date. The preview remains private until you manually send it through the canonical Messages interface.</p><label>My template ID<input name=\"id\" type=\"number\" min=\"1\" required></label><label>Recipient name<input name=\"recipient_name\" maxlength=\"191\"></label><label>Clinic name<input name=\"clinic_name\" maxlength=\"191\"></label><label>Appointment date<input name=\"appointment_date\" maxlength=\"191\"></label><button type=\"submit\">Preview without sending</button></form>",
    "<form id=\"snf-device-list-form\" class=\"snf-card\"><h3>My current public device-key records</h3><p>Read-only verification metadata. These records are not raw private keys and do not certify an audited E2EE protocol.</p><button type=\"submit\">View my device records</button></form>",
    "<form id=\"snf-key-transparency-form\" class=\"snf-card\"><h3>Device-key change history (authorized contacts)</h3><p>Read-only, privacy-minimized evidence. This is not independent verification of a signed transparency checkpoint.</p><label>Account ID<input name=\"user_id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Read permitted key-change history</button></form>",
    "<form id=\"snf-lobby-read-form\" class=\"snf-card\"><h3>View current waiting-room status</h3><p>Current call members only; a stale result does not authorize entry.</p><label>Call ID<input name=\"call_id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Read waiting room</button></form>",
    "<form id=\"snf-speaker-queue-read-form\" class=\"snf-card\"><h3>View current speaker queue</h3><p>Read-only: call moderator rights are enforced separately for queue changes.</p><label>Call ID<input name=\"call_id\" type=\"number\" min=\"1\" required></label><button type=\"submit\">Read speaker queue</button></form>",
    "<form id=\"snf-network-quality-form\" class=\"snf-card\"><h3>My current call network quality</h3><p>Enter estimates provided by your browser or call client; this form does not independently measure your connection. Telemetry storage is opt-in and short-lived.</p><label>Call ID<input name=\"call_id\" type=\"number\" min=\"1\" required></label><label>Packet loss %<input name=\"packet_loss_pct\" type=\"number\" min=\"0\" max=\"100\" step=\"0.01\" required></label><label>Round-trip latency (ms)<input name=\"rtt_ms\" type=\"number\" min=\"0\" max=\"5000\" step=\"1\" required></label><label>Jitter (ms)<input name=\"jitter_ms\" type=\"number\" min=\"0\" max=\"5000\" step=\"1\" required></label><label>Downlink bandwidth (kbps)<input name=\"downlink_kbps\" type=\"number\" min=\"0\" max=\"1000000\" step=\"1\" required></label><label><input name=\"telemetry_consent\" type=\"checkbox\"> I consent to short-lived, minimal telemetry storage</label><button type=\"submit\">Get call quality guidance</button></form>",
    "</div></details>",
  ].join('');
  root.insertAdjacentHTML('beforeend', nextJourneyForms);

  const parseIds = (raw, limit = 100) => {
    const parts = String(raw || '').trim().split(/[\s,]+/).filter(Boolean);
    if (!parts.length || parts.length > limit || parts.some(value => !/^[1-9][0-9]*$/.test(value) || !Number.isSafeInteger(Number(value)))) throw new Error('Enter between 1 and ' + limit + ' valid positive numeric IDs.');
    return [...new Set(parts.map(Number))];
  };
  const futureISO = raw => {
    const date = new Date(String(raw || ''));
    if (!Number.isFinite(date.getTime()) || date.getTime() <= Date.now()) throw new Error('Select a future local date and time.');
    return date.toISOString();
  };
  bind('snf-revisions-form', async data => ({status_message:'Authorized message revision response is displayed below.',workspace_readout:await api('future/messages/' + Number(data.get('message_id')) + '/versions'),workspace_title:'Authorized message versions — private'}));
  bind('snf-bulk-form', data => {
    const preview = data.get('preview') === 'on';
    if (!preview && !window.confirm('Execute this operation across the selected conversations? Each item requires its own authorization.')) throw new Error('Batch cancelled before submission.');
    return api('future/conversations/bulk', {method:'POST',body:{conversation_ids:parseIds(data.get('conversation_ids')),action:String(data.get('action') || ''),label:String(data.get('label') || ''),assignee_id:Number(data.get('assignee_id') || 0),preview}});
  });
  bind('snf-invite-create-form', data => api('future/community-invites', {method:'POST',body:{space_id:Number(data.get('space_id')),role:String(data.get('role')),expires_in_hours:Number(data.get('expires_in_hours')),max_uses:Number(data.get('max_uses'))}}));
  bind('snf-invite-redeem-form', data => api('future/community-invites/redeem', {method:'POST',body:{token:String(data.get('token') || '')}}));
  bind('snf-temp-member-form', data => api('future/temporary-memberships', {method:'POST',body:{space_id:Number(data.get('space_id')),user_id:Number(data.get('user_id')),role:String(data.get('role') || 'observer'),expires_at:futureISO(data.get('expires_at'))}}));
  bind('snf-mentorship-decision-form', data => api('future/mentorships/' + Number(data.get('id')), {method:'POST',body:{decision:String(data.get('decision') || 'decline')}}));
  bind('snf-case-form', data => { if (data.get('consent_asserted') !== 'on') throw new Error('Consent and de-identification assertion is mandatory.'); return api('future/case-discussions', {method:'POST',body:{conversation_id:Number(data.get('conversation_id')),age_band:String(data.get('age_band') || ''),sex:String(data.get('sex') || 'unspecified'),summary:String(data.get('summary') || ''),consent_asserted:true}}); });
  bind('snf-handraise-form', data => api('calls/' + Number(data.get('call_id')) + '/hand-raise', {method:'POST',body:{raised:data.get('raised') === 'on'}}));
  bind('snf-lobby-form', data => api('calls/' + Number(data.get('call_id')) + '/lobby', {method:'POST',body:{user_id:Number(data.get('user_id')),state:String(data.get('state') || 'waiting')}}));
  bind('snf-ai-form', async data => { if (data.get('explicit_consent') !== 'on') throw new Error('Explicit consent is required before selected messages can be sent to the approved File 16 AI adapter.'); return {status_message:'An authorized AI adapter returned a response. Review any output before using it.',workspace_readout:await api('future/ai-assistant', {method:'POST',body:{conversation_id:Number(data.get('conversation_id')),message_ids:parseIds(data.get('message_ids'),20),task:String(data.get('task') || 'summary'),target_locale:String(data.get('target_locale') || ''),explicit_consent:true}}),workspace_title:'AI response — not a clinical decision'}; });
  bind('snf-semantic-form', async data => ({status_message:'Private authorized semantic search result is shown below.',workspace_readout:await api('future/semantic-search', {method:'POST',body:{conversation_id:Number(data.get('conversation_id')),query:String(data.get('query') || '')}}),workspace_title:'Private semantic search results'}));
  bind('snf-safety-form', async data => ({status_message:'Compare this number out of band with the other participant; do not infer E2EE from the number alone.',workspace_readout:await api('future/device-keys/' + Number(data.get('user_id')) + '/safety-number'),workspace_title:'Safety number comparison'}));

  bind('snf-my-records-form', async data => ({status_message:'Current authorized collection is shown below.',workspace_readout:await api(String(data.get('type'))),workspace_title:'My private workspace items'}));
  bind('snf-smart-results-form', async data => ({status_message:'Private saved view rechecked your current access.',workspace_readout:await api('future/smart-views/' + Number(data.get('id')) + '/results'),workspace_title:'Private saved view'}));
  bind('snf-bulk-status-form', async data => ({status_message:'Batch status is shown below; a queued request does not imply completion.',workspace_readout:await api('future/conversations/bulk/' + Number(data.get('id'))),workspace_title:'Bounded batch progress'}));
  bind('snf-bulk-cancel-form', data => { if (!window.confirm('Cancel this pending batch? Already applied changes cannot be undone automatically.')) throw new Error('Cancellation was not submitted.'); return api('future/conversations/bulk/' + Number(data.get('id')), {method:'DELETE'}); });
  bind('snf-semantic-consent-form', async data => ({status_message:'The consent update response is below. A purge_pending flag means data erasure still awaits provider confirmation.',workspace_readout:await api('future/semantic-search/consent', {method:'POST',body:{enabled:data.get('enabled') === 'yes'}}),workspace_title:'Private search consent and purge state'}));
  bind('snf-revoke-record-form', data => { if (!window.confirm('Request erasure of this authorized private record? This may be irreversible.')) throw new Error('Record erasure not submitted.'); return api('future/records/' + Number(data.get('id')), {method:'DELETE'}); });
  bind('snf-invite-revoke-form', data => { if (!window.confirm('Revoke this invitation? A previously copied link should cease working.')) throw new Error('Invitation revocation not submitted.'); return api('future/community-invites/' + Number(data.get('id')) + '/revoke', {method:'POST'}); });
  bind('snf-mentorship-end-form', data => { if (!window.confirm('End this mentorship?')) throw new Error('Mentorship end not submitted.'); return api('future/mentorships/' + Number(data.get('id')) + '/end', {method:'POST'}); });
  // A reminder cannot be changed after dispatch starts; server enforces record ownership and CAS.
  bind('snf-reminder-cancel-form', data => {
    if (!window.confirm('Cancel this pending reminder?')) throw new Error('Reminder cancellation not submitted.');
    return api('future/reminders/' + Number(data.get('id')) + '/cancel', {method:'POST',body:{expected_version:Number(data.get('expected_version'))}});
  });
  bind('snf-reminder-reschedule-form', data => api('future/reminders/' + Number(data.get('id')) + '/reschedule', {method:'POST',body:{expected_version:Number(data.get('expected_version')),remind_at:futureISO(data.get('remind_at'))}}));
  bind('snf-team-inbox-read-form', async data => ({status_message:'Current authorized team assignment shown below. Copy the version into the team assignment form before updating.',workspace_readout:await api('future/team-inbox/' + Number(data.get('conversation_id'))),workspace_title:'Team assignment version'}));
  bind('snf-template-preview-form', async data => ({
    status_message:'Preview generated privately. No message was sent.',
    workspace_readout:await api('future/templates/' + Number(data.get('id')) + '/preview', {method:'POST',body:{
      variables:{recipient_name:String(data.get('recipient_name') || ''),clinic_name:String(data.get('clinic_name') || ''),appointment_date:String(data.get('appointment_date') || '')}
    }}),
    workspace_title:'Plain-text saved reply preview — NOT SENT'
  }));
  bind('snf-device-list-form', async () => ({status_message:'The account-owned public key metadata is displayed without private secret material.',workspace_readout:await api('future/device-keys'),workspace_title:'My device-key metadata — NOT an E2EE certification'}));
  bind('snf-key-transparency-form', async data => ({status_message:'Read-only permitted key-change records shown below; checkpoint consistency is not independently proven here.',workspace_readout:await api('future/key-transparency/' + Number(data.get('user_id'))),workspace_title:'Permitted device-key change history'}));
  // Accessible suggestions use only conversations currently returned by canonical File-17 membership filtering.
  // A stale selection is never authority; every action is rechecked server-side.
  async function loadConversationChoices() {
    if (typeof root.querySelectorAll !== 'function') return;
    const fields = [...root.querySelectorAll('input[name="conversation_id"]')];
    if (!fields.length) return;
    try {
      const result = await api('conversations');
      const rows = Array.isArray(result?.conversations) ? result.conversations : [];
      const choices = document.createElement('datalist');
      choices.id = 'snf-authorized-conversation-choices';
      const seen = new Set();
      for (const item of rows.slice(0, 300)) {
        const id = Number(item?.id);
        if (!Number.isSafeInteger(id) || id < 1 || seen.has(id)) continue;
        seen.add(id);
        const option = document.createElement('option');
        option.value = String(id);
        option.label = String(item?.title || 'Conversation').slice(0, 100);
        choices.append(option);
      }
      if (!seen.size) return;
      root.append(choices);
      fields.forEach(field => field.setAttribute('list', choices.id));
    } catch (_error) {
      // Existing numeric controls remain usable if the current list cannot be retrieved.
    }
  }
  // FUT-17/18: safe GETs; FUT-21: input diagnostics, not invented measurements.
  bind('snf-lobby-read-form', async data => ({status_message:'Current authorized call waiting-room state is shown below.',workspace_readout:await api('calls/' + Number(data.get('call_id')) + '/lobby'),workspace_title:'Current call waiting room'}));
  bind('snf-speaker-queue-read-form', async data => ({status_message:'Current authorized speaker queue is shown below.',workspace_readout:await api('calls/' + Number(data.get('call_id')) + '/speaker-queue'),workspace_title:'Current speaker queue'}));
  bind('snf-network-quality-form', async data => {
    const number = (key, min, max) => {
      const raw=String(data.get(key) ?? '').trim();
      if (!raw) throw new Error('A measured value is required: '+key);
      const value=Number(raw);
      if (!Number.isFinite(value) || value < min || value > max) throw new Error('Quality metric is outside the permitted range: '+key);
      return value;
    };
    const metrics={
      packet_loss_pct:number('packet_loss_pct',0,100),
      rtt_ms:number('rtt_ms',0,5000),
      jitter_ms:number('jitter_ms',0,5000),
      downlink_kbps:number('downlink_kbps',0,1000000),
      telemetry_consent:data.get('telemetry_consent') === 'on'
    };
    return {status_message:metrics.telemetry_consent?'Current quality guidance received; telemetry retention is server-governed.':'Current guidance received without requested telemetry storage.',workspace_readout:await api('calls/' + Number(data.get('call_id')) + '/network-quality',{method:'POST',body:metrics}),workspace_title:'Call network guidance — not an active speed test'};
  });
  loadConversationChoices();
  loadCapabilities();
})();
