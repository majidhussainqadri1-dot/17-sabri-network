#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')

def replace(rel, old, new, count=1):
    p=root/rel; t=p.read_text(encoding='utf-8'); n=t.count(old)
    if n < count: raise SystemExit(f'{rel}: expected {count}, found {n}: {old[:120]!r}')
    p.write_text(t.replace(old,new,count),encoding='utf-8')

# Critical hourly maintenance schedule: activation must fail closed; runtime records durable failure evidence.
replace('includes/class-sn-activator.php',
"        self::ensure_cleanup_schedule();\n        flush_rewrite_rules(false);\n",
"        $schedule = self::ensure_cleanup_schedule();\n        if (is_wp_error($schedule)) throw new RuntimeException($schedule->get_error_message());\n        flush_rewrite_rules(false);\n",1)
replace('includes/class-sn-activator.php',
"    public static function ensure_cleanup_schedule(): void {\n        if (!wp_next_scheduled('sn_cleanup_hourly')) {\n            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sn_cleanup_hourly');\n        }\n    }\n",
"    public static function ensure_cleanup_schedule(): bool|WP_Error {\n        if (wp_next_scheduled('sn_cleanup_hourly')) { delete_option('sn_cleanup_schedule_error'); return true; }\n        $scheduled = wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sn_cleanup_hourly', [], true);\n        if (is_wp_error($scheduled) || $scheduled === false) {\n            $code = is_wp_error($scheduled) ? $scheduled->get_error_code() : 'schedule_failed';\n            update_option('sn_cleanup_schedule_error', sanitize_key($code), false);\n            if (class_exists('SN_DB')) SN_DB::audit('cleanup_schedule_failed', 'system', 0, 'failure', ['reason'=>sanitize_key($code)], 0);\n            return is_wp_error($scheduled) ? $scheduled : new WP_Error('sn_cleanup_schedule_failed', 'File 17 hourly maintenance could not be scheduled.');\n        }\n        delete_option('sn_cleanup_schedule_error');\n        return true;\n    }\n")

# Outbox delivery schedule must be a health criterion, not a best-effort silent call.
replace('includes/class-sn-outbox.php',
"    private static function ensure_schedule(): void {\n        if (!wp_next_scheduled('sn_network_outbox_tick')) wp_schedule_event(time() + MINUTE_IN_SECONDS, 'sn_every_minute', 'sn_network_outbox_tick');\n    }\n",
"    private static function ensure_schedule(): bool|WP_Error {\n        if (wp_next_scheduled('sn_network_outbox_tick')) { delete_option('sn_outbox_schedule_error'); return true; }\n        $scheduled = wp_schedule_event(time() + MINUTE_IN_SECONDS, 'sn_every_minute', 'sn_network_outbox_tick', [], true);\n        if (is_wp_error($scheduled) || $scheduled === false) {\n            $code = is_wp_error($scheduled) ? $scheduled->get_error_code() : 'schedule_failed';\n            update_option('sn_outbox_schedule_error', sanitize_key($code), false);\n            SN_DB::audit('event_delivery_schedule_failed','event',0,'failure',['reason'=>sanitize_key($code)],0);\n            return is_wp_error($scheduled) ? $scheduled : new WP_Error('sn_outbox_schedule_failed','File 17 event delivery could not be scheduled.');\n        }\n        delete_option('sn_outbox_schedule_error');\n        return true;\n    }\n")
replace('includes/class-sn-outbox.php',
"        return rest_ensure_response(['ok'=>$outbox_exists&&$inbox_exists,'outbox_table'=>$outbox_exists,'inbox_table'=>$inbox_exists,'schema_version'=>(string)get_option('sn_event_delivery_schema_version',''),'counts'=>$counts,'next_run'=>(int)wp_next_scheduled('sn_network_outbox_tick'),'max_attempts'=>self::max_attempts(),'time'=>gmdate('c')]);\n",
"        $next_run=(int)wp_next_scheduled('sn_network_outbox_tick');$schedule_error=(string)get_option('sn_outbox_schedule_error','');\n        return rest_ensure_response(['ok'=>$outbox_exists&&$inbox_exists&&$next_run>0&&$schedule_error==='','outbox_table'=>$outbox_exists,'inbox_table'=>$inbox_exists,'schema_version'=>(string)get_option('sn_event_delivery_schema_version',''),'counts'=>$counts,'next_run'=>$next_run,'schedule_error'=>$schedule_error,'max_attempts'=>self::max_attempts(),'time'=>gmdate('c')]);\n")

# Private-byte retry scheduling failure must be visible and durable.
p=root/'includes/class-sn-private-files.php'; t=p.read_text(encoding='utf-8')
old="                wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'sn_network_retry_private_delete', [$attachment_id]);\n"
new="                $scheduled = wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'sn_network_retry_private_delete', [$attachment_id], true);\n                if (is_wp_error($scheduled) || $scheduled === false) SN_DB::audit('attachment_delete_retry_schedule_failed','attachment',$attachment_id,'failure',['reason'=>is_wp_error($scheduled)?$scheduled->get_error_code():'schedule_failed'],$actor_id);\n"
if t.count(old)!=1: raise SystemExit(f'private initial retry anchor count {t.count(old)}')
t=t.replace(old,new,1)
old2="            wp_schedule_single_event(time() + min(HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * (2 ** $attempts)), 'sn_network_retry_private_delete', [$attachment_id]);\n"
new2="            $scheduled = wp_schedule_single_event(time() + min(HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * (2 ** $attempts)), 'sn_network_retry_private_delete', [$attachment_id], true);\n            if (is_wp_error($scheduled) || $scheduled === false) SN_DB::audit('attachment_delete_retry_schedule_failed','attachment',$attachment_id,'failure',['attempt'=>$attempts,'reason'=>is_wp_error($scheduled)?$scheduled->get_error_code():'schedule_failed'],0);\n"
if t.count(old2)!=1: raise SystemExit(f'private retry anchor count {t.count(old2)}')
p.write_text(t.replace(old2,new2,1),encoding='utf-8')

# Repair the prior R9 literal so the contract itself does not interpolate PHP variables.
p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8')
t=t.replace('str_contains($rest,"$raw_reaction = trim")', "str_contains($rest,'$raw_reaction = trim')")
anchor="\nif ($fail) {\n"
if anchor not in t: raise SystemExit('ninth suite anchor missing')
block=r'''
// Round 10 — critical cron/retry scheduling is fail-closed and observable.
$activator = $read('includes/class-sn-activator.php');
$outbox = $read('includes/class-sn-outbox.php');
$private = $read('includes/class-sn-private-files.php');
$check(str_contains($activator, "wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sn_cleanup_hourly', [], true)") && str_contains($activator, "sn_cleanup_schedule_error") && str_contains($activator, 'is_wp_error($schedule)'), 'Round 10: hourly cleanup scheduling must expose failure and fail activation closed.');
$check(str_contains($outbox, "wp_schedule_event(time() + MINUTE_IN_SECONDS, 'sn_every_minute', 'sn_network_outbox_tick', [], true)") && str_contains($outbox, "sn_outbox_schedule_error") && str_contains($outbox, "'ok'=>\$outbox_exists&&\$inbox_exists&&\$next_run>0"), 'Round 10: outbox health must require a successfully scheduled delivery tick.');
$check(substr_count($private, "attachment_delete_retry_schedule_failed") >= 2 && substr_count($private, "wp_schedule_single_event") >= 2 && str_contains($private, '[$attachment_id], true'), 'Round 10: private-byte retry scheduling failures must be audited rather than silently discarded.');
'''
p.write_text(t.replace(anchor,"\n"+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-activator.php
php -l sabri-network/includes/class-sn-outbox.php
php -l sabri-network/includes/class-sn-private-files.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
