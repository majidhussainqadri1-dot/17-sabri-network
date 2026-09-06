from pathlib import Path

# R1-D01 / R1-D02: migration governor truth and legacy preservation.
p=Path('sabri-network/includes/class-sn-fifth-fresh-migration-hardening.php')
s=p.read_text(encoding='utf-8')

old="""                update_option(self::STATE_OPTION, [
                    'status'=>'complete','from'=>$from,'to'=>SN_VERSION,'completed_at'=>gmdate('c'),
                    'verification'=>'all-governed-installer-tables-plus-critical-columns-pass',
                    'completion_path'=>'post-lock-fast-path',
                ], false);
                return true;
"""
new="""                $complete_state = [
                    'status'=>'complete','from'=>$from,'to'=>SN_VERSION,'completed_at'=>gmdate('c'),
                    'verification'=>'all-governed-installer-tables-plus-critical-columns-pass',
                    'completion_path'=>'post-lock-fast-path',
                ];
                update_option(self::STATE_OPTION, $complete_state, false);
                $stored_state = get_option(self::STATE_OPTION, null);
                if (!is_array($stored_state) || ($stored_state['status'] ?? '') !== 'complete' || ($stored_state['to'] ?? '') !== SN_VERSION) {
                    throw new RuntimeException('migration_state_publish_failed');
                }
                return true;
"""
if old not in s: raise SystemExit('fast-path state target missing')
s=s.replace(old,new,1)

old="""            update_option('sn_plugin_version', SN_VERSION, false);
            update_option(self::STATE_OPTION, [
                'status'=>'complete','from'=>$from,'to'=>SN_VERSION,'completed_at'=>gmdate('c'),
                'verification'=>'all-governed-installer-tables-plus-critical-columns-pass',
            ], false);
            return true;
"""
new="""            update_option('sn_plugin_version', SN_VERSION, false);
            if ((string)get_option('sn_plugin_version','') !== SN_VERSION) {
                throw new RuntimeException('migration_version_publish_failed');
            }
            $complete_state = [
                'status'=>'complete','from'=>$from,'to'=>SN_VERSION,'completed_at'=>gmdate('c'),
                'verification'=>'all-governed-installer-tables-plus-critical-columns-pass',
            ];
            update_option(self::STATE_OPTION, $complete_state, false);
            $stored_state = get_option(self::STATE_OPTION, null);
            if (!is_array($stored_state) || ($stored_state['status'] ?? '') !== 'complete' || ($stored_state['to'] ?? '') !== SN_VERSION) {
                throw new RuntimeException('migration_state_publish_failed');
            }
            return true;
"""
if old not in s: raise SystemExit('final publication target missing')
s=s.replace(old,new,1)

old="""        $legacy_exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($legacy))) === $legacy;
        $backup_exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($backup))) === $backup;
"""
new="""        $wpdb->last_error = '';
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
"""
if old not in s: raise SystemExit('legacy preservation target missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# R1-D02: normal init is no longer a second schema installer/version authority.
p=Path('sabri-network/sabri-network.php')
s=p.read_text(encoding='utf-8')
start=s.index('    public function init(): void {\n')
marker=s.index("        do_action('sn_network_relationship_contract_registered'", start)
replacement="""    public function init(): void {
        // The serialized migration governor runs at init priority -1000 and is the
        // sole schema/version authority. Normal init must never invoke installers
        // or publish schema/plugin versions independently.
        SN_Central_Plan_Hardening::maybe_upgrade();
        SN_Activator::ensure_cleanup_schedule();
        add_rewrite_tag('%sn_network_app%', '1');
        add_rewrite_rule('^network-safe/?$', 'index.php?sn_network_app=1', 'top');

"""
s=s[:start]+replacement+s[marker:]
p.write_text(s,encoding='utf-8')

# R1-D03: active plaintext-body migration proves transaction start.
p=Path('sabri-network/includes/class-sn-central-plan-hardening.php')
s=p.read_text(encoding='utf-8')
pos=s.index('    public static function migrate_message_bodies(): void {')
target="""        foreach ($rows as $row) {
            $wpdb->query('START TRANSACTION');
            try {
"""
idx=s.find(target,pos)
if idx<0: raise SystemExit('plaintext migration transaction target missing')
repl="""        foreach ($rows as $row) {
            if ($wpdb->query('START TRANSACTION') === false) {
                SN_DB::audit('message_body_encryption_migration_failed', 'message', (int) $row->id, 'failure', ['reason' => 'transaction_start_failed'], 0);
                break;
            }
            try {
"""
s=s[:idx]+s[idx:].replace(target,repl,1)
p.write_text(s,encoding='utf-8')

# Permanent regression in an already-governed suite; inventory count stays stable.
p=Path('sabri-network/tests/seventh-fresh-ten-round-contracts.php')
s=p.read_text(encoding='utf-8')
if "$bootstrap=$read('sabri-network.php');" not in s:
    anchor="$migration=$read('includes/class-sn-fifth-fresh-migration-hardening.php');\n"
    if anchor not in s: raise SystemExit('regression variable anchor missing')
    s=s.replace(anchor,anchor+"$bootstrap=$read('sabri-network.php');\n$centralPlan=$read('includes/class-sn-central-plan-hardening.php');\n",1)
marker='// Yet-another R1 migration governance regressions.'
if marker not in s:
    insert="""
// Yet-another R1 migration governance regressions.
$check(str_contains($migration,'legacy_otp_discovery_failed')&&str_contains($migration,'backup_otp_discovery_failed'),'Yet R1: legacy OTP preservation must fail closed when table-discovery truth is unavailable.');
$check(str_contains($migration,'migration_version_publish_failed')&&str_contains($migration,'migration_state_publish_failed'),'Yet R1: migration completion requires durable version and state publication.');
$check(!str_contains($bootstrap,'SN_DB::maybe_upgrade();')&&!str_contains($bootstrap,"if ((string) get_option('sn_plugin_version', '') !== SN_VERSION"),'Yet R1: normal init must not remain a second schema installer/version publisher.');
$migStart=strpos($centralPlan,"if ($wpdb->query('START TRANSACTION') === false)",strpos($centralPlan,'public static function migrate_message_bodies'));
$migWrite=strpos($centralPlan,'SN_Message_Body::ensure_encrypted_row($row)',strpos($centralPlan,'public static function migrate_message_bodies'));
$check($migStart!==false&&$migWrite!==false&&$migStart<$migWrite,'Yet R1: plaintext body migration must prove transaction start before the first mutation.');
"""
    tail=s.rfind('if($fail){')
    if tail<0: raise SystemExit('regression tail missing')
    s=s[:tail]+insert+s[tail:]
p.write_text(s,encoding='utf-8')
