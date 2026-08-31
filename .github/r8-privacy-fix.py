from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
inc = root / 'sabri-network' / 'includes'

def replace(path, old, new):
    text = path.read_text(encoding='utf-8')
    if old not in text:
        raise SystemExit(f'missing R8 anchor in {path}: {old[:80]}')
    path.write_text(text.replace(old, new, 1), encoding='utf-8')

r15 = inc / 'class-sn-seventh-fresh-r15-privacy-hardening.php'
replace(r15,
"""        if (self::target_hold_blocks_eraser($key, $uid)) {
            return self::retained(__('This File 17 data class is directly relevant to an active target-specific legal hold.', 'sabri-network'));
        }
""",
"""        $target_hold = self::target_hold_blocks_eraser($key, $uid);
        if (is_wp_error($target_hold)) {
            return self::retry(__('Target-specific legal-hold verification failed; erasure must be retried.', 'sabri-network'));
        }
        if ($target_hold) {
            return self::retained(__('This File 17 data class is directly relevant to an active target-specific legal hold.', 'sabri-network'));
        }
""")
replace(r15,
"""            case 'sabri-network-message-receipts': return self::exists(SN_DB::table('message_receipts'), 'user_id=%d', [$uid]);
            case 'sabri-network-future':
""",
"""            case 'sabri-network-message-receipts': return self::exists(SN_DB::table('message_receipts'), 'user_id=%d', [$uid]);
            case 'sabri-network-contexts': return self::exists(SN_DB::table('conversation_contexts'), 'attached_by=%d', [$uid]);
            case 'sabri-network-two-plan-idempotency': return self::exists(SN_DB::table('two_plan_idempotency'), "actor_id=%d AND state='complete'", [$uid]);
            case 'sabri-network-future':
""")
text = r15.read_text(encoding='utf-8')
start = text.index('    private static function target_hold_blocks_eraser(')
end = text.index('    private static function message_organization_remaining', start)
new_hold = r'''    private static function target_hold_blocks_eraser(string $key, int $uid): bool|WP_Error {
        $reports = SN_DB::table('reports');
        if ($key === 'sabri-network-spaces' || $key === 'sabri-network') {
            $space = self::hold_exists(
                "SELECT r.id FROM $reports r INNER JOIN ".SN_DB::table('space_members')." sm ON sm.space_id=CAST(r.target_ref AS UNSIGNED) AND sm.user_id=%d WHERE r.legal_hold=1 AND r.target_type='space' LIMIT 1",
                [$uid]
            );
            if (is_wp_error($space) || $space) return $space;
        }
        if ($key === 'sabri-network') {
            $call = self::hold_exists(
                "SELECT r.id FROM $reports r INNER JOIN ".SN_DB::table('call_members')." cm ON cm.call_id=CAST(r.target_ref AS UNSIGNED) AND cm.user_id=%d WHERE r.legal_hold=1 AND r.target_type='call' LIMIT 1",
                [$uid]
            );
            if (is_wp_error($call) || $call) return $call;
            $conversation = self::hold_exists(
                "SELECT r.id FROM $reports r INNER JOIN ".SN_DB::table('members')." m ON m.conversation_id=CAST(r.target_ref AS UNSIGNED) AND m.user_id=%d AND m.left_at IS NULL WHERE r.legal_hold=1 AND r.target_type='conversation' LIMIT 1",
                [$uid]
            );
            if (is_wp_error($conversation) || $conversation) return $conversation;
        }
        return false;
    }

    private static function hold_exists(string $sql, array $args): bool|WP_Error {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare($sql, ...$args));
        if ($wpdb->last_error !== '') return new WP_Error('privacy_hold_verification_failed', 'Target-specific legal-hold verification failed.');
        return $value !== null;
    }

'''
r15.write_text(text[:start] + new_hold + text[end:], encoding='utf-8')

integration = inc / 'class-sn-fifth-fresh-integration-hardening.php'
replace(integration,
"""        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE attached_by=%d ORDER BY id ASC LIMIT %d",
            $uid,
            self::BATCH
        )) ?: []);
        if (!$ids) return self::done();
""",
"""        $raw_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE attached_by=%d ORDER BY id ASC LIMIT %d",
            $uid,
            self::BATCH
        ));
        if (!is_array($raw_ids) || $wpdb->last_error !== '') return self::retry('Conversation-context erasure could not enumerate its work.');
        $ids = array_map('intval', $raw_ids);
        if (!$ids) return self::done();
""")

firewall = inc / 'class-sn-two-plan-contract-firewall.php'
text = firewall.read_text(encoding='utf-8')
old = """        $removed = $wpdb->query($wpdb->prepare("DELETE FROM ".self::table()." WHERE actor_id=%d AND state='complete' LIMIT 100", (int) $user->ID));
        return ['items_removed' => (int) $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => (int) $removed < 100];
"""
new = """        $uid = (int) $user->ID;
        $removed = $wpdb->query($wpdb->prepare("DELETE FROM ".self::table()." WHERE actor_id=%d AND state='complete' LIMIT 100", $uid));
        if ($removed === false) return ['items_removed'=>false,'items_retained'=>true,'messages'=>['Communication request-cache erasure failed and must be retried.'],'done'=>false];
        $remaining = $wpdb->get_var($wpdb->prepare("SELECT scope_key FROM ".self::table()." WHERE actor_id=%d AND state='complete' LIMIT 1", $uid));
        if ($wpdb->last_error !== '') return ['items_removed'=>(int)$removed>0,'items_retained'=>true,'messages'=>['Communication request-cache erasure completion could not be verified.'],'done'=>false];
        return ['items_removed'=>(int)$removed>0,'items_retained'=>false,'messages'=>[],'done'=>$remaining===null];
"""
if old not in text: raise SystemExit('missing firewall eraser anchor')
firewall.write_text(text.replace(old,new,1), encoding='utf-8')

# Permanent R8 regression.
test = root / 'sabri-network' / 'tests' / 'eighth-fresh' / 'eighth-fresh-ten-round-contracts.php'
source = test.read_text(encoding='utf-8')
if '$privacyR15 =' not in source:
    source = source.replace("$firewall = $read('includes/class-sn-two-plan-contract-firewall.php');", "$firewall = $read('includes/class-sn-two-plan-contract-firewall.php');\n$privacyR15 = $read('includes/class-sn-seventh-fresh-r15-privacy-hardening.php');\n$integrationPrivacy = $read('includes/class-sn-fifth-fresh-integration-hardening.php');")
marker = '// Round 8 — terminal privacy completion and legal-hold verification must fail closed.'
if marker not in source:
    block = r'''
// Round 8 — terminal privacy completion and legal-hold verification must fail closed.
$check(str_contains($privacyR15, 'privacy_hold_verification_failed') && str_contains($privacyR15, 'is_wp_error($target_hold)'), 'Round 8: target-specific legal-hold database failures must stop erasure and retry.');
$check(str_contains($privacyR15, "case 'sabri-network-contexts'") && str_contains($privacyR15, "case 'sabri-network-two-plan-idempotency'"), 'Round 8: terminal completion verification must cover context attribution and idempotency-cache rows.');
$check(str_contains($integrationPrivacy, '$raw_ids = $wpdb->get_col') && str_contains($integrationPrivacy, "$wpdb->last_error !== ''"), 'Round 8: context erasure enumeration failure must not become a false done result.');
$check(str_contains($firewall, 'Communication request-cache erasure failed and must be retried.') && str_contains($firewall, "$remaining = $wpdb->get_var"), 'Round 8: idempotency-cache erasure must verify both delete success and completion.');
'''
    source = source.replace('\nif ($fail) {', block + '\nif ($fail) {')
test.write_text(source, encoding='utf-8')
print('R8 privacy corrections applied')
