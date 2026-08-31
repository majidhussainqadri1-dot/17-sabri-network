#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')

def replace(rel, old, new, count=1):
    p=root/rel; t=p.read_text(encoding='utf-8'); n=t.count(old)
    if n < count: raise SystemExit(f'{rel}: expected {count}, found {n}: {old!r}')
    p.write_text(t.replace(old,new,count),encoding='utf-8')

# Final forward route: caller key is mandatory; never invent one server-side.
replace('includes/class-sn-compatibility-hardening.php',
"        $client = strtolower(trim((string) $request->get_param('client_id'))) ?: wp_generate_uuid4();\n",
"        $client = strtolower(trim((string) $request->get_param('client_id')));\n")

# Canonical internal upload/message sender used by voice notes: fail closed on missing key.
replace('includes/class-sn-message-integrity.php',
"        $client_id = strtolower(trim((string) $request->get_param('client_id'))) ?: wp_generate_uuid4();\n",
"        $client_id = strtolower(trim((string) $request->get_param('client_id')));\n")

# Final voice-note surface validates before upload work and before forwarding internally.
p=root/'includes/class-sn-fifth-fresh-feature-hardening.php'
t=p.read_text(encoding='utf-8')
old="""        $files = $request->get_file_params();
        if (empty($files['attachment']) || !is_array($files['attachment'])) {
"""
new="""        $client_id = strtolower(trim((string)$request->get_param('client_id')));
        if ($client_id === '' || !preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $client_id)) {
            return new WP_Error('invalid_client_id', 'A caller-supplied voice-note idempotency key is required.', ['status'=>400]);
        }
        $files = $request->get_file_params();
        if (empty($files['attachment']) || !is_array($files['attachment'])) {
"""
if old not in t: raise SystemExit('voice-note preflight anchor missing')
t=t.replace(old,new,1)
t=t.replace("        $forward->set_param('client_id', (string)$request->get_param('client_id'));\n","        $forward->set_param('client_id', $client_id);\n",1)
p.write_text(t,encoding='utf-8')

# Append permanent R8 contracts into the single ninth-fresh suite.
p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'
t=p.read_text(encoding='utf-8')
anchor="\nif ($fail) {\n"
if anchor not in t: raise SystemExit('ninth suite final anchor missing')
block=r'''
// Round 8 — retryable message-create surfaces require caller-owned idempotency keys.
$compat = $read('includes/class-sn-compatibility-hardening.php');
$integrity = $read('includes/class-sn-message-integrity.php');
$voice = $read('includes/class-sn-fifth-fresh-feature-hardening.php');
$check(!str_contains($compat, "get_param('client_id'))) ?: wp_generate_uuid4()") && str_contains($compat, "$client = strtolower(trim((string) $request->get_param('client_id')));"), 'Round 8: forwarding must never invent a client idempotency key.');
$check(!str_contains($integrity, "get_param('client_id'))) ?: wp_generate_uuid4()") && str_contains($integrity, "$client_id = strtolower(trim((string) $request->get_param('client_id')));"), 'Round 8: the internal message sender must fail closed when caller idempotency is absent.');
$voicePos = strpos($voice, 'public static function send_voice_note');
$voiceEnd = $voicePos === false ? false : strpos($voice, 'public static function structured_message', $voicePos);
$voiceSeg = $voicePos === false ? '' : substr($voice, $voicePos, ($voiceEnd === false ? strlen($voice) : $voiceEnd) - $voicePos);
$check(str_contains($voiceSeg, "A caller-supplied voice-note idempotency key is required.") && str_contains($voiceSeg, "$forward->set_param('client_id', $client_id);"), 'Round 8: final voice-note creation must validate and preserve the caller idempotency key before upload/message creation.');
'''
p.write_text(t.replace(anchor,"\n"+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-compatibility-hardening.php
php -l sabri-network/includes/class-sn-message-integrity.php
php -l sabri-network/includes/class-sn-fifth-fresh-feature-hardening.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
