#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')
p=root/'includes/class-sn-central-plan-hardening.php'
t=p.read_text(encoding='utf-8')
old=r'''    public static function route_notification_to_file19(bool $handled, array $event): bool {
        if (!$handled) {
            do_action('sn_network_notification_requested', [
                'producer' => 'file-17',
                'user_id' => absint($event['user_id'] ?? 0),
                'type' => sanitize_key((string) ($event['type'] ?? '')),
                'title' => sanitize_text_field((string) ($event['title'] ?? '')),
                'entity_type' => sanitize_key((string) ($event['entity_type'] ?? '')),
                'entity_id' => absint($event['entity_id'] ?? 0),
                'created_at' => sanitize_text_field((string) ($event['created_at'] ?? current_time('mysql', true))),
            ]);
            if (class_exists('SN_DB')) {
                SN_DB::audit('notification_deferred_to_file19', sanitize_key((string) ($event['entity_type'] ?? '')), absint($event['entity_id'] ?? 0), 'success', [
                    'notification_type' => sanitize_key((string) ($event['type'] ?? '')),
                    'recipient_id' => absint($event['user_id'] ?? 0),
                ]);
            }
        }
        return true;
    }
'''
new=r'''    public static function route_notification_to_file19(bool $handled, array $event): bool {
        if ($handled) return true;

        $requested = [
            'producer' => 'file-17',
            'recipient_id' => absint($event['user_id'] ?? 0),
            'type' => sanitize_key((string) ($event['type'] ?? '')),
            'title' => sanitize_text_field((string) ($event['title'] ?? '')),
            'entity_type' => sanitize_key((string) ($event['entity_type'] ?? '')),
            'entity_id' => absint($event['entity_id'] ?? 0),
            'created_at' => sanitize_text_field((string) ($event['created_at'] ?? current_time('mysql', true))),
        ];
        $requested['idempotency_key'] = 'file17-notification:' . hash('sha256', implode('|', [
            (string)$requested['recipient_id'], (string)$requested['type'], (string)$requested['entity_type'],
            (string)$requested['entity_id'], (string)$requested['created_at'],
        ]));

        $ready = class_exists('SN_Seventh_Fresh_R13_Hardening')
            ? SN_Seventh_Fresh_R13_Hardening::file19_ready()
            : (has_action('sn_network_notification_requested') !== false && apply_filters('sn_network_file19_notification_adapter_ready', false) === true);
        if (!$ready) {
            if (class_exists('SN_DB')) SN_DB::audit('notification_file19_unavailable', $requested['entity_type'], $requested['entity_id'], 'failure', [
                'notification_type'=>$requested['type'], 'recipient_id'=>$requested['recipient_id'],
                'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
            ], 0);
            return true; // File 17's deprecated local center must remain disabled.
        }

        try {
            do_action('sn_network_notification_requested', $requested);
            $ack = apply_filters('sn_network_notification_delivery_result', null, $requested);
            if (is_wp_error($ack) || $ack !== true) {
                if (class_exists('SN_DB')) SN_DB::audit('notification_file19_handoff_unacknowledged', $requested['entity_type'], $requested['entity_id'], 'failure', [
                    'notification_type'=>$requested['type'], 'recipient_id'=>$requested['recipient_id'],
                    'reason'=>is_wp_error($ack) ? $ack->get_error_code() : 'missing_explicit_ack',
                    'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
                ], 0);
                return true;
            }
            if (class_exists('SN_DB')) SN_DB::audit('notification_deferred_to_file19', $requested['entity_type'], $requested['entity_id'], 'success', [
                'notification_type'=>$requested['type'], 'recipient_id'=>$requested['recipient_id'],
                'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
            ], 0);
        } catch (Throwable $error) {
            if (class_exists('SN_DB')) SN_DB::audit('notification_file19_handoff_failed', $requested['entity_type'], $requested['entity_id'], 'failure', [
                'notification_type'=>$requested['type'], 'recipient_id'=>$requested['recipient_id'], 'reason'=>$error->getMessage(),
                'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
            ], 0);
        }
        return true;
    }
'''
if old not in t: raise SystemExit('central notification bridge anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')

p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t: raise SystemExit('suite anchor missing')
block=r'''
// Round 17 — generic File-19 notification handoff is truthful and explicitly acknowledged.
$central = $read('includes/class-sn-central-plan-hardening.php');
$check(str_contains($central, "SN_Seventh_Fresh_R13_Hardening::file19_ready()") && str_contains($central, "notification_file19_unavailable"), 'Round 17: generic notifications must verify File 19 readiness before claiming a handoff.');
$check(str_contains($central, "apply_filters('sn_network_notification_delivery_result', null, $requested)") && str_contains($central, "notification_file19_handoff_unacknowledged") && str_contains($central, "missing_explicit_ack"), 'Round 17: generic notification handoff success requires explicit File 19 acknowledgement.');
$check(str_contains($central, "file17-notification:") && str_contains($central, "idempotency_key_hash") && str_contains($central, "notification_deferred_to_file19") && str_contains($central, "'success'"), 'Round 17: File 19 handoffs carry stable evidence and success is recorded only on the acknowledged path.');
'''
p.write_text(t.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-central-plan-hardening.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
