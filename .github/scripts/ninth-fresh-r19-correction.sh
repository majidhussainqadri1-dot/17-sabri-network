#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')

# CF01 child final read must be retry-safe.
p=root/'includes/class-sn-fifth-fresh-integration-hardening.php'; t=p.read_text(encoding='utf-8')
t=t.replace("""        if (!is_array($rows)) return self::retry('Clinical-context reference erasure could not be read safely.');""","""        if (!is_array($rows) || $wpdb->last_error !== '') return self::retry('Clinical-context reference erasure could not be read safely.');""",1)
t=t.replace("""        $more = (bool)$wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $table WHERE issued_by=%d AND status='active' LIMIT 1\", $uid));
        return [""","""        $more_raw = $wpdb->get_var($wpdb->prepare(\"SELECT 1 FROM $table WHERE issued_by=%d AND status='active' LIMIT 1\", $uid));
        if ($wpdb->last_error !== '') return self::retry('Clinical-context reference erasure completion could not be verified.');
        $more = $more_raw !== null;
        return [""",1)
p.write_text(t,encoding='utf-8')

# Two-plan extension eraser must surface DB uncertainty and write failures.
p=root/'includes/class-sn-fourth-fresh-privacy-hardening.php'; t=p.read_text(encoding='utf-8')
old="""        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            \"SELECT pv.id FROM $votes pv
             WHERE pv.user_id=%d
               AND NOT EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=pv.message_id AND r.legal_hold=1)
             ORDER BY pv.id ASC LIMIT 100\",
            $uid
        )) ?: []);
        $removed = 0;
        foreach ($ids as $id) {
            if ($wpdb->delete($votes, ['id'=>$id,'user_id'=>$uid], ['%d','%d']) === 1) $removed++;
        }
        $held = (int)$wpdb->get_var($wpdb->prepare(
            \"SELECT COUNT(*) FROM $votes pv WHERE pv.user_id=%d AND EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=pv.message_id AND r.legal_hold=1)\",
            $uid
        ));
"""
new="""        $raw_ids = $wpdb->get_col($wpdb->prepare(
            \"SELECT pv.id FROM $votes pv
             WHERE pv.user_id=%d
               AND NOT EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=pv.message_id AND r.legal_hold=1)
             ORDER BY pv.id ASC LIMIT 100\",
            $uid
        ));
        if (!is_array($raw_ids) || $wpdb->last_error !== '') return ['items_removed'=>false,'items_retained'=>true,'messages'=>['Poll-vote erasure could not enumerate its work.'],'done'=>false];
        $ids = array_map('intval', $raw_ids);
        $removed = 0;
        foreach ($ids as $id) {
            $deleted = $wpdb->delete($votes, ['id'=>$id,'user_id'=>$uid], ['%d','%d']);
            if ($deleted === false) return ['items_removed'=>$removed>0,'items_retained'=>true,'messages'=>['Poll-vote erasure must be retried.'],'done'=>false];
            if ($deleted === 1) $removed++;
        }
        $held_raw = $wpdb->get_var($wpdb->prepare(
            \"SELECT COUNT(*) FROM $votes pv WHERE pv.user_id=%d AND EXISTS (SELECT 1 FROM $reports r WHERE r.message_id=pv.message_id AND r.legal_hold=1)\",
            $uid
        ));
        if ($wpdb->last_error !== '') return ['items_removed'=>$removed>0,'items_retained'=>true,'messages'=>['Poll-vote legal-hold verification must be retried.'],'done'=>false];
        $held = (int)$held_raw;
"""
if old not in t: raise SystemExit('R19 two-plan vote anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

# Base Two-Plan eraser: raw query failures must not cast to negative/zero progress.
p=root/'includes/class-sn-two-plan-completion.php'; t=p.read_text(encoding='utf-8')
old="""        global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$uid=(int)$user->ID;$removed=0;
        $removed+=(int)$wpdb->query($wpdb->prepare(\"DELETE FROM \".self::scheduled_table().\" WHERE sender_id=%d AND status IN ('pending','cancelled','failed') LIMIT 100\",$uid));
        $removed+=(int)$wpdb->query($wpdb->prepare(\"UPDATE \".self::requests_table().\" SET body_cipher='',reason='',updated_at=%s WHERE requester_id=%d AND status IN ('declined','cancelled') AND body_cipher<>'' LIMIT 100\",current_time('mysql',true),$uid));
        return['items_removed'=>$removed>0,'items_retained'=>false,'messages'=>[],'done'=>$removed<200];
"""
new="""        global $wpdb;$user=get_user_by('email',$email);if(!$user)return['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];$uid=(int)$user->ID;$removed=0;
        $scheduled=$wpdb->query($wpdb->prepare(\"DELETE FROM \".self::scheduled_table().\" WHERE sender_id=%d AND status IN ('pending','cancelled','failed') LIMIT 100\",$uid));
        if($scheduled===false)return['items_removed'=>false,'items_retained'=>true,'messages'=>['Scheduled-message privacy erasure must be retried.'],'done'=>false];
        $requests=$wpdb->query($wpdb->prepare(\"UPDATE \".self::requests_table().\" SET body_cipher='',reason='',updated_at=%s WHERE requester_id=%d AND status IN ('declined','cancelled') AND body_cipher<>'' LIMIT 100\",current_time('mysql',true),$uid));
        if($requests===false)return['items_removed'=>$scheduled>0,'items_retained'=>true,'messages'=>['Message-request privacy erasure must be retried.'],'done'=>false];
        $removed=(int)$scheduled+(int)$requests;
        return['items_removed'=>$removed>0,'items_retained'=>false,'messages'=>[],'done'=>$removed<200];
"""
if old not in t: raise SystemExit('R19 base two-plan eraser anchor missing')
t=t.replace(old,new,1)
p.write_text(t,encoding='utf-8')

# Terminal verifier covers every residual data class that can otherwise false-complete.
p=root/'includes/class-sn-seventh-fresh-r15-privacy-hardening.php'; t=p.read_text(encoding='utf-8')
anchor="""            case 'sabri-network-contexts': return self::exists(SN_DB::table('conversation_contexts'), 'attached_by=%d', [$uid]);
            case 'sabri-network-two-plan-idempotency': return self::exists(SN_DB::table('two_plan_idempotency'), \"actor_id=%d AND state='complete'\", [$uid]);
"""
insert="""            case 'sabri-network-contexts': return self::exists(SN_DB::table('conversation_contexts'), 'attached_by=%d', [$uid]);
            case 'sabri-network-cf01-references': return self::exists(SN_DB::table('cf01_context_refs'), \"issued_by=%d AND status='active'\", [$uid]);
            case 'sabri-network-two-plan':
                $scheduled = self::exists(SN_DB::table('scheduled_messages'), \"sender_id=%d AND status IN ('pending','cancelled','failed')\", [$uid]); if (is_wp_error($scheduled) || $scheduled) return $scheduled;
                $requests = self::exists(SN_DB::table('message_requests'), \"requester_id=%d AND status IN ('declined','cancelled') AND body_cipher<>''\", [$uid]); if (is_wp_error($requests) || $requests) return $requests;
                return self::exists(SN_DB::table('poll_votes'), \"user_id=%d AND NOT EXISTS (SELECT 1 FROM \".SN_DB::table('reports').\" r WHERE r.message_id=\".SN_DB::table('poll_votes').\".message_id AND r.legal_hold=1)\", [$uid]);
            case 'sabri-meet':
                foreach ([
                    [SN_DB::table('meet_sessions'),'user_id=%d'],
                    [SN_DB::table('meet_participants'),'user_id=%d'],
                    [SN_DB::table('meet_signals'),'(from_user_id=%d OR to_user_id=%d)'],
                    [SN_DB::table('meet_events'),'(actor_id=%d OR subject_user_id=%d)'],
                    [SN_DB::table('meet_meetings'),'host_id=%d'],
                ] as [$table,$where]) {
                    $args = substr_count($where, '%d') === 2 ? [$uid,$uid] : [$uid];
                    $left = self::exists($table, $where, $args); if (is_wp_error($left) || $left) return $left;
                }
                return false;
            case 'sabri-network-two-plan-idempotency': return self::exists(SN_DB::table('two_plan_idempotency'), \"actor_id=%d AND state='complete'\", [$uid]);
"""
if anchor not in t: raise SystemExit('R19 terminal verifier anchor missing')
t=t.replace(anchor,insert,1)
p.write_text(t,encoding='utf-8')

# Permanent R19 regression contracts.
p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t: raise SystemExit('R19 suite anchor missing')
block=r'''
// Round 19 — terminal privacy completion independently verifies CF01, Two-Plan and Meet.
$privacy = $read('includes/class-sn-seventh-fresh-r15-privacy-hardening.php');
$integration = $read('includes/class-sn-fifth-fresh-integration-hardening.php');
$twoPlanPrivacy = $read('includes/class-sn-fourth-fresh-privacy-hardening.php');
$twoPlan = $read('includes/class-sn-two-plan-completion.php');
foreach (['sabri-network-cf01-references','sabri-network-two-plan','sabri-meet'] as $key) $check(str_contains($privacy, "case '$key':"), 'Round 19: terminal verifier lost coverage for '.$key.'.');
$check(str_contains($integration, 'Clinical-context reference erasure completion could not be verified.') && str_contains($integration, '$wpdb->last_error'), 'Round 19: CF01 child completion query must fail closed.');
$check(str_contains($twoPlanPrivacy, 'Poll-vote erasure could not enumerate its work.') && str_contains($twoPlanPrivacy, 'Poll-vote legal-hold verification must be retried.'), 'Round 19: poll-vote erasure DB uncertainty must remain retryable.');
$check(str_contains($twoPlan, 'Scheduled-message privacy erasure must be retried.') && str_contains($twoPlan, 'Message-request privacy erasure must be retried.'), 'Round 19: Two-Plan base eraser write failures must remain retryable.');
'''
p.write_text(t.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')
PY
for f in sabri-network/includes/class-sn-fifth-fresh-integration-hardening.php sabri-network/includes/class-sn-fourth-fresh-privacy-hardening.php sabri-network/includes/class-sn-two-plan-completion.php sabri-network/includes/class-sn-seventh-fresh-r15-privacy-hardening.php sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php; do php -l "$f"; done
