#!/usr/bin/env bash
set -euo pipefail
python3 - <<'PY'
from pathlib import Path
root=Path('sabri-network')
p=root/'includes/class-sn-db.php'; t=p.read_text(encoding='utf-8')
old="""    public static function private_attachment_is_referenced(int $attachment_id): bool {
        global $wpdb;
        if ($attachment_id <= 0) {
            return false;
        }
        $message_reference = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('messages') . ' WHERE attachment_id=%d AND attachment_source=%s AND deleted_at IS NULL LIMIT 1',
            $attachment_id,
            'private'
        ));
        if ($message_reference) {
            return true;
        }
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('updates') . ' WHERE media_id=%d AND media_source=%s LIMIT 1',
            $attachment_id,
            'private'
        ));
    }
"""
new="""    public static function private_attachment_is_referenced(int $attachment_id): bool {
        global $wpdb;
        if ($attachment_id <= 0) {
            return false;
        }
        $message_reference = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('messages') . ' WHERE attachment_id=%d AND attachment_source=%s AND deleted_at IS NULL LIMIT 1',
            $attachment_id,
            'private'
        ));
        if ($wpdb->last_error !== '') {
            self::audit('attachment_reference_check_failed', 'attachment', $attachment_id, 'failure', ['source'=>'messages','reason'=>(string)$wpdb->last_error], 0);
            return true;
        }
        if ($message_reference) {
            return true;
        }
        $update_reference = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('updates') . ' WHERE media_id=%d AND media_source=%s LIMIT 1',
            $attachment_id,
            'private'
        ));
        if ($wpdb->last_error !== '') {
            self::audit('attachment_reference_check_failed', 'attachment', $attachment_id, 'failure', ['source'=>'updates','reason'=>(string)$wpdb->last_error], 0);
            return true;
        }
        return $update_reference !== null;
    }
"""
if old not in t: raise SystemExit('R23 reference anchor missing')
t=t.replace(old,new,1)
old2="""                $wpdb->query('COMMIT');
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                self::audit('expired_update_cleanup_failed', 'update', 0, 'failure', ['batch' => $batch]);
                break;
            }

            // Delete bytes only after canonical update records are gone, and never while
"""
new2="""                if ($wpdb->query('COMMIT') === false) {
                    throw new RuntimeException('expired_update_commit_failed');
                }
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                self::audit('expired_update_cleanup_failed', 'update', 0, 'failure', ['batch' => $batch, 'reason' => sanitize_key($e->getMessage())]);
                break;
            }

            // Delete bytes only after a confirmed commit removed canonical update records, and never while
"""
if old2 not in t: raise SystemExit('R23 commit anchor missing')
t=t.replace(old2,new2,1)
p.write_text(t,encoding='utf-8')

p=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; t=p.read_text(encoding='utf-8'); anchor='\nif ($fail) {\n'
if anchor not in t: raise SystemExit('R23 suite anchor missing')
block=r'''
// Round 23 — private attachment bytes are retained on reference/commit uncertainty.
$db=$read('includes/class-sn-db.php');
$refPos=strpos($db,'public static function private_attachment_is_referenced');$refEnd=$refPos===false?false:strpos($db,'public static function cleanup_expired',$refPos);$refSeg=$refPos===false?'':substr($db,$refPos,($refEnd===false?strlen($db):$refEnd)-$refPos);
$check(substr_count($refSeg,'$wpdb->last_error')>=2 && substr_count($refSeg,'attachment_reference_check_failed')>=2 && substr_count($refSeg,'return true;')>=3, 'Round 23: private attachment reference DB uncertainty must retain bytes.');
$cleanupPos=strpos($db,'public static function cleanup_expired');$cleanupEnd=$cleanupPos===false?false:strpos($db,'private static function migrate_contacts',$cleanupPos);$cleanupSeg=$cleanupPos===false?'':substr($db,$cleanupPos,($cleanupEnd===false?strlen($db):$cleanupEnd)-$cleanupPos);
$check(str_contains($cleanupSeg,"query('COMMIT') === false") && str_contains($cleanupSeg,'expired_update_commit_failed') && strpos($cleanupSeg,"query('COMMIT') === false") < strpos($cleanupSeg,'SN_Private_Files::delete'), 'Round 23: expired update attachment bytes require a confirmed commit before deletion.');
'''
p.write_text(t.replace(anchor,'\n'+block+anchor,1),encoding='utf-8')
PY
php -l sabri-network/includes/class-sn-db.php
php -l sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php
