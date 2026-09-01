#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')
# Native hold authority fails closed on DB uncertainty.
p=root/'includes/class-sn-fourth-fresh-privacy-hardening.php'; t=p.read_text(encoding='utf-8')
old="""        $held = (int) $wpdb->get_var($wpdb->prepare(
            \"SELECT r.id
             FROM $reports r
             LEFT JOIN $messages m ON m.id=r.message_id
             WHERE r.legal_hold=1
               AND (r.reporter_id=%d OR r.reported_user_id=%d OR m.sender_id=%d)
             LIMIT 1\",
            $user_id,
            $user_id,
            $user_id
        ));
        return $held > 0;
"""
new="""        $held_raw = $wpdb->get_var($wpdb->prepare(
            \"SELECT r.id
             FROM $reports r
             LEFT JOIN $messages m ON m.id=r.message_id
             WHERE r.legal_hold=1
               AND (r.reporter_id=%d OR r.reported_user_id=%d OR m.sender_id=%d)
             LIMIT 1\",
            $user_id,
            $user_id,
            $user_id
        ));
        // Retention authority is safety-critical: database uncertainty must retain, not erase.
        if ($wpdb->last_error !== '') {
            if (class_exists('SN_DB')) SN_DB::audit('native_legal_hold_verification_failed','user',$user_id,'failure',['reason'=>(string)$wpdb->last_error],0);
            return true;
        }
        return $held_raw !== null;
"""
if old not in t: raise SystemExit('R20 hold anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')
# Blocking must not claim success if transaction durability is unconfirmed.
p=root/'includes/class-sn-rest.php'; t=p.read_text(encoding='utf-8')
old="""            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::database_error();
        }
        SN_DB::audit($blocked ? 'user_blocked' : 'user_unblocked', 'user', $target_id);
"""
new="""            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('block_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return self::database_error();
        }
        SN_DB::audit($blocked ? 'user_blocked' : 'user_unblocked', 'user', $target_id);
"""
if old not in t: raise SystemExit('R20 block commit anchor missing')
p.write_text(t.replace(old,new,1),encoding='utf-8')
# Permanent regression, using non-interpolating test needles.
p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
block=r'''
// Round 20 — moderation retention and blocking durability fail closed.
$safetyPrivacy=$read('includes/class-sn-fourth-fresh-privacy-hardening.php');
$rest=$read('includes/class-sn-rest.php');
$check(str_contains($safetyPrivacy,'native_legal_hold_verification_failed') && str_contains($safetyPrivacy,'$wpdb->last_error') && str_contains($safetyPrivacy,'return true;'), 'Round 20: native legal-hold DB uncertainty must retain rather than fail open.');
$blockPos=strpos($rest,'public static function block_user'); $blockEnd=$blockPos===false?false:strpos($rest,'public static function admin_reports',$blockPos); $blockSeg=$blockPos===false?'':substr($rest,$blockPos,($blockEnd===false?strlen($rest):$blockEnd)-$blockPos);
$check(str_contains($blockSeg,"block_commit_failed") && str_contains($blockSeg,"query('COMMIT') === false"), 'Round 20: block/unblock success requires a confirmed transaction commit.');
'''
if anchor not in t: raise SystemExit('R20 suite anchor missing')
p.write_text(t.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-fourth-fresh-privacy-hardening.php
php -l sabri-network/includes/class-sn-rest.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
