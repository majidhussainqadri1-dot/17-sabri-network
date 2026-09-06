from pathlib import Path

m=Path('sabri-network/includes/class-sn-fifth-fresh-migration-hardening.php')
s=m.read_text(encoding='utf-8')

old="""        $snapshot = self::version_snapshot();
        $from = (string)get_option('sn_plugin_version','');
        update_option(self::STATE_OPTION, ['status'=>'running','from'=>$from,'to'=>SN_VERSION,'started_at'=>gmdate('c')], false);
        try {
"""
new="""        $snapshot = self::version_snapshot();
        $from = (string)get_option('sn_plugin_version','');
        $legacy_otp_renamed = false;
        update_option(self::STATE_OPTION, ['status'=>'running','from'=>$from,'to'=>SN_VERSION,'started_at'=>gmdate('c')], false);
        try {
"""
if old not in s: raise SystemExit('R16 migration state anchor missing')
s=s.replace(old,new,1)

old="""            self::preserve_legacy_otp_table();
            foreach (self::installers() as [$class,$method]) {
"""
new="""            $legacy_otp_renamed = self::preserve_legacy_otp_table();
            foreach (self::installers() as [$class,$method]) {
"""
if old not in s: raise SystemExit('R16 preserve call anchor missing')
s=s.replace(old,new,1)

old="""        } catch (Throwable $e) {
            self::restore_version_snapshot($snapshot);
            update_option(self::STATE_OPTION, [
                'status'=>'failed','from'=>$from,'to'=>SN_VERSION,'failed_at'=>gmdate('c'),
                'reason'=>substr(sanitize_text_field($e->getMessage()),0,500),
            ], false);
            if (class_exists('SN_DB')) SN_DB::audit('schema_upgrade_failed','migration',0,'failure',['reason'=>$e->getMessage()],0);
            return new WP_Error('sn_migration_failed','File 17 schema upgrade did not pass post-migration verification and will be retried safely.',['status'=>503]);
        } finally {
"""
new="""        } catch (Throwable $e) {
            $rollback_error = '';
            if ($legacy_otp_renamed) {
                try {
                    self::restore_legacy_otp_table();
                } catch (Throwable $rollback) {
                    $rollback_error = substr(sanitize_text_field($rollback->getMessage()), 0, 500);
                }
            }
            self::restore_version_snapshot($snapshot);
            $state = [
                'status'=>'failed','from'=>$from,'to'=>SN_VERSION,'failed_at'=>gmdate('c'),
                'reason'=>substr(sanitize_text_field($e->getMessage()),0,500),
                'rollback_status'=>$rollback_error === '' ? 'restored' : 'failed',
            ];
            if ($rollback_error !== '') $state['rollback_reason'] = $rollback_error;
            update_option(self::STATE_OPTION, $state, false);
            if (class_exists('SN_DB')) SN_DB::audit('schema_upgrade_failed','migration',0,'failure',['reason'=>$e->getMessage(),'rollback_status'=>$state['rollback_status']],0);
            if ($rollback_error !== '') {
                return new WP_Error('sn_migration_rollback_failed','File 17 schema upgrade failed and legacy rollback restoration could not be proven. Manual recovery is required before retrying.',['status'=>503]);
            }
            return new WP_Error('sn_migration_failed','File 17 schema upgrade did not pass post-migration verification and will be retried safely.',['status'=>503]);
        } finally {
"""
if old not in s: raise SystemExit('R16 catch anchor missing')
s=s.replace(old,new,1)

old="            'event_outbox'=>['event_uuid','event_key','event_type','status','attempts','version'],"
new="            'event_outbox'=>['event_uuid','event_key','event_type','event_contract','event_schema_version','status','attempts','version'],"
if old not in s: raise SystemExit('R16 outbox verify anchor missing')
s=s.replace(old,new,1)

old="""    /** Preserve legacy File-17 OTP data for rollback evidence before the old installer retires its table. */
    private static function preserve_legacy_otp_table(): void {
        global $wpdb;
        $legacy = $wpdb->prefix . 'sn_phone_otps';
        $backup = $wpdb->prefix . 'sn_phone_otps_f17_retired';
        $wpdb->last_error = '';
        $legacy_raw = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($legacy)));
        if ($wpdb->last_error !== '' || ($legacy_raw !== null && !is_string($legacy_raw))) {
            throw new RuntimeException('legacy_otp_discovery_failed');
        }
        $legacy_exists = (string)$legacy_raw === $legacy;
        $wpdb->last_error = '';
        $backup_raw = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($backup)));
        if ($wpdb->last_error !== '' || ($backup_raw !== null && !is_string($backup_raw))) {
            throw new RuntimeException('backup_otp_discovery_failed');
        }
        $backup_exists = (string)$backup_raw === $backup;
        if ($legacy_exists && !$backup_exists) {
            $ok = $wpdb->query('RENAME TABLE `' . esc_sql($legacy) . '` TO `' . esc_sql($backup) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ($ok === false) throw new RuntimeException('legacy_otp_preservation_failed');
        }
    }
"""
new="""    /** Preserve legacy File-17 OTP data and return whether this invocation renamed it. */
    private static function preserve_legacy_otp_table(): bool {
        global $wpdb;
        $legacy = $wpdb->prefix . 'sn_phone_otps';
        $backup = $wpdb->prefix . 'sn_phone_otps_f17_retired';
        $wpdb->last_error = '';
        $legacy_raw = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($legacy)));
        if ($wpdb->last_error !== '' || ($legacy_raw !== null && !is_string($legacy_raw))) {
            throw new RuntimeException('legacy_otp_discovery_failed');
        }
        $legacy_exists = (string)$legacy_raw === $legacy;
        $wpdb->last_error = '';
        $backup_raw = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($backup)));
        if ($wpdb->last_error !== '' || ($backup_raw !== null && !is_string($backup_raw))) {
            throw new RuntimeException('backup_otp_discovery_failed');
        }
        $backup_exists = (string)$backup_raw === $backup;
        if ($legacy_exists && $backup_exists) {
            throw new RuntimeException('legacy_otp_backup_conflict');
        }
        if (!$legacy_exists) return false;
        $ok = $wpdb->query('RENAME TABLE `' . esc_sql($legacy) . '` TO `' . esc_sql($backup) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($ok === false) throw new RuntimeException('legacy_otp_preservation_failed');
        return true;
    }

    /** Restore the physical legacy OTP name when this migration invocation fails after preservation. */
    private static function restore_legacy_otp_table(): void {
        global $wpdb;
        $legacy = $wpdb->prefix . 'sn_phone_otps';
        $backup = $wpdb->prefix . 'sn_phone_otps_f17_retired';
        $wpdb->last_error = '';
        $legacy_raw = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($legacy)));
        if ($wpdb->last_error !== '') throw new RuntimeException('legacy_otp_rollback_discovery_failed');
        $wpdb->last_error = '';
        $backup_raw = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($backup)));
        if ($wpdb->last_error !== '') throw new RuntimeException('backup_otp_rollback_discovery_failed');
        if ((string)$legacy_raw === $legacy) throw new RuntimeException('legacy_otp_rollback_target_conflict');
        if ((string)$backup_raw !== $backup) throw new RuntimeException('legacy_otp_rollback_source_missing');
        $ok = $wpdb->query('RENAME TABLE `' . esc_sql($backup) . '` TO `' . esc_sql($legacy) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($ok === false) throw new RuntimeException('legacy_otp_rollback_failed');
        $restored = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($legacy)));
        if ($restored !== $legacy) throw new RuntimeException('legacy_otp_rollback_unverified');
    }
"""
if old not in s: raise SystemExit('R16 legacy OTP method anchor missing')
s=s.replace(old,new,1)
m.write_text(s,encoding='utf-8')

a=Path('sabri-network/includes/class-sn-activator.php')
s=a.read_text(encoding='utf-8')
old="""        self::set_defaults();
        self::retire_legacy_secrets();
        // Activation delegates all schema/version publication to the serialized
"""
new="""        self::set_defaults();
        // Activation delegates all schema/version publication to the serialized
"""
if old not in s: raise SystemExit('R16 activator pre-migration retirement anchor missing')
s=s.replace(old,new,1)
old="""        self::ensure_cleanup_schedule();
        flush_rewrite_rules(false);
    }
"""
new="""        self::ensure_cleanup_schedule();
        flush_rewrite_rules(false);
        // Destructive retirement is deliberately last: a failed governed migration
        // or later activation readiness check must not mutate the prior installation.
        self::retire_legacy_secrets();
    }
"""
if old not in s: raise SystemExit('R16 activator success anchor missing')
s=s.replace(old,new,1)
a.write_text(s,encoding='utf-8')

test=Path('sabri-network/tests/r16-migration-rollback-contracts.php')
test.write_text(r'''<?php
/** Fresh 20-round Round-16 migration/rollback contracts. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$m=$read('includes/class-sn-fifth-fresh-migration-hardening.php');
$a=$read('includes/class-sn-activator.php');
$o=$read('includes/class-sn-outbox.php');
$check(str_contains($o,"SCHEMA_VERSION = '1.1.0'")&&str_contains($o,'event_contract VARCHAR(160)')&&str_contains($o,'event_schema_version VARCHAR(20)'),'R16-D01 precondition: outbox schema requires the Round-15 contract columns.');
$check(str_contains($m,"'event_outbox'=>['event_uuid','event_key','event_type','event_contract','event_schema_version','status','attempts','version']"),'R16-D01: authoritative migration verification must require both new outbox columns.');
$check(str_contains($m,"sn_plugin_version','') === SN_VERSION && self::verify_schema()"),'R16-D01: same-version fast path remains gated by physical schema verification.');
$check(str_contains($m,'$legacy_otp_renamed = false;')&&str_contains($m,'$legacy_otp_renamed = self::preserve_legacy_otp_table();'),'R16-D02: migration invocation must track whether it physically renamed legacy OTP storage.');
$check(str_contains($m,'restore_legacy_otp_table()')&&str_contains($m,"'rollback_status'=>")&&str_contains($m,'sn_migration_rollback_failed'),'R16-D02: failure path must restore and report physical rollback truth.');
$check(str_contains($m,'legacy_otp_backup_conflict'),'R16-D02: concurrent/pre-existing backup conflict must fail closed without destructive overwrite.');
$check(str_contains($m,'legacy_otp_rollback_source_missing')&&str_contains($m,'legacy_otp_rollback_target_conflict')&&str_contains($m,'legacy_otp_rollback_unverified'),'R16-D02: rollback restoration must verify source, target and final physical state.');
$upgrade=strpos($a,'SN_Fifth_Fresh_Migration_Hardening::upgrade(true)');
$retire=strpos($a,'self::retire_legacy_secrets();');
$flush=strpos($a,'flush_rewrite_rules(false);');
$check($upgrade!==false&&$retire!==false&&$retire>$upgrade,'R16-D03: destructive legacy-secret retirement must occur only after governed migration success.');
$check($flush!==false&&$retire>$flush,'R16-D03: legacy-secret retirement must be the final destructive activation step after readiness checks.');
$prefix=substr($a,0,$upgrade);
$check(!str_contains($prefix,'self::retire_legacy_secrets();'),'R16-D03: no pre-migration destructive retirement may remain.');
if($fail){fwrite(STDERR,"R16 migration/rollback failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "R16 migration/rollback contracts: PASS ($checks checks)\n";
''',encoding='utf-8')

q=Path('sabri-network/tools/quality-check.sh')
s=q.read_text(encoding='utf-8')
needle=' sixth-fresh-twenty-round-contracts.php seventh-fresh-ten-round-contracts.php r15-event-delivery-schema-contracts.php\n)'
repl=' sixth-fresh-twenty-round-contracts.php seventh-fresh-ten-round-contracts.php r15-event-delivery-schema-contracts.php r16-migration-rollback-contracts.php\n)'
if 'r16-migration-rollback-contracts.php' not in s:
    if needle not in s: raise SystemExit('R16 quality inventory anchor missing')
    s=s.replace(needle,repl,1)
q.write_text(s,encoding='utf-8')
