from pathlib import Path

p=Path('sabri-network/includes/class-sn-fifth-fresh-migration-hardening.php')
s=p.read_text(encoding='utf-8')
old="""        if (!$force && (string)get_option('sn_plugin_version','') === SN_VERSION && self::verify_schema()) return true;
"""
new="""        if (!$force && (string)get_option('sn_plugin_version','') === SN_VERSION && self::verify_schema()) {
            $from = (string)get_option('sn_plugin_version','');
            $complete_state = [
                'status'=>'complete','from'=>$from,'to'=>SN_VERSION,'completed_at'=>gmdate('c'),
                'verification'=>'all-governed-installer-tables-plus-critical-columns-pass',
                'completion_path'=>'pre-lock-fast-path',
            ];
            update_option(self::STATE_OPTION, $complete_state, false);
            $stored_state = get_option(self::STATE_OPTION, null);
            if (!is_array($stored_state) || ($stored_state['status'] ?? '') !== 'complete' || ($stored_state['to'] ?? '') !== SN_VERSION) {
                return new WP_Error('sn_migration_state_unavailable','File 17 schema is current but migration state could not be published safely. Retry.',['status'=>503]);
            }
            return true;
        }
"""
if old not in s: raise SystemExit('pre-lock fast path target missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

p=Path('sabri-network/tests/fifth-fresh-migration-contracts.php')
t=p.read_text(encoding='utf-8')
marker="\nif($fail){fwrite(STDERR,"
insert="""
// Fresh 20-round R2 migration-state truth regression.
$pre=strpos($m,"'completion_path'=>'pre-lock-fast-path'");
$post=strpos($m,"'completion_path'=>'post-lock-fast-path'");
$check($pre!==false&&$post!==false,'Fresh20 R2: both verified migration fast paths must publish explicit completion state.');
$check(str_contains($m,"'sn_migration_state_unavailable'")&&str_contains($m,"['status'=>503]"),'Fresh20 R2: pre-lock completion-state publication failure must fail closed and retryably.');
$check(substr_count($m,'migration_state_publish_failed')>=2,'Fresh20 R2: post-lock and install completion publication must remain verified.');
$check(!str_contains($m,"self::verify_schema()) return true;"),'Fresh20 R2: no verified migration fast path may bypass durable migration-state truth.');
"""
if marker not in t: raise SystemExit('migration test tail missing')
t=t.replace(marker,'\n'+insert+marker,1)
p.write_text(t,encoding='utf-8')
