from pathlib import Path


def replace_once(path: str, old: str, new: str, marker: str) -> None:
    p = Path(path)
    s = p.read_text(encoding='utf-8')
    if new in s:
        return
    if old not in s:
        raise SystemExit(f'{marker}: target mismatch')
    p.write_text(s.replace(old, new, 1), encoding='utf-8')

# R1-D01 activation/page repair truth.
replace_once(
    'sabri-network/includes/class-sn-activator.php',
    "        SN_Messages::ensure_pages();\n",
    "        $message_pages = SN_Messages::ensure_pages();\n        if (($message_pages['messages'] ?? 0) <= 0 || ($message_pages['settings'] ?? 0) <= 0) throw new RuntimeException('File 17 Messages pages could not be created safely.');\n",
    'activator message pages'
)
replace_once(
    'sabri-network/includes/class-sn-activator.php',
    """            if ($repair || !has_shortcode((string) $page->post_content, 'sabri_network') || $page->post_status !== 'publish') {
                wp_update_post([
                    'ID' => $page_id,
                    'post_title' => 'Network',
                    'post_content' => '[sabri_network]',
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                ]);
            }
            return $page_id;
""",
    """            if ($repair || !has_shortcode((string) $page->post_content, 'sabri_network') || $page->post_status !== 'publish') {
                $updated = wp_update_post([
                    'ID' => $page_id,
                    'post_title' => 'Network',
                    'post_content' => '[sabri_network]',
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                ], true);
                if (is_wp_error($updated) || (int) $updated !== $page_id) return 0;
                $page = get_post($page_id);
            }
            if (!$page instanceof WP_Post || $page->post_status !== 'publish' || !has_shortcode((string) $page->post_content, 'sabri_network')) return 0;
            return $page_id;
""",
    'network page repair'
)
replace_once(
    'sabri-network/includes/class-sn-messages.php',
    """            if ($repair || !has_shortcode((string) $page->post_content, trim($shortcode, '[]')) || $page->post_status !== 'publish') {
                wp_update_post([
                    'ID' => $page_id,
                    'post_title' => $title,
                    'post_content' => $shortcode,
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                ]);
            }
            return $page_id;
""",
    """            if ($repair || !has_shortcode((string) $page->post_content, trim($shortcode, '[]')) || $page->post_status !== 'publish') {
                $updated = wp_update_post([
                    'ID' => $page_id,
                    'post_title' => $title,
                    'post_content' => $shortcode,
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                ], true);
                if (is_wp_error($updated) || (int) $updated !== $page_id) return 0;
                $page = get_post($page_id);
            }
            if (!$page instanceof WP_Post || $page->post_status !== 'publish' || !has_shortcode((string) $page->post_content, trim($shortcode, '[]'))) return 0;
            return $page_id;
""",
    'messages page repair'
)
replace_once(
    'sabri-network/includes/class-sn-file-transfer-part-7.php',
    "    public static function ensure_page(bool $repair): int{$id=(int)get_option('sn_file_transfer_page_id');$page=$id?get_post($id):null;if($page instanceof WP_Post&&(string)get_post_meta($id,self::PAGE_OWNER_META,true)==='file-transfer'){if($repair||!has_shortcode((string)$page->post_content,'sabri_file_transfer')||$page->post_status!=='publish')wp_update_post(['ID'=>$id,'post_title'=>'File Transfer','post_content'=>'[sabri_file_transfer]','post_status'=>'publish','comment_status'=>'closed']);return$id;}$candidate=get_page_by_path('file-transfer',OBJECT,'page');if($candidate instanceof WP_Post&&(string)get_post_meta((int)$candidate->ID,self::PAGE_OWNER_META,true)!=='file-transfer')return 0;$created=$candidate instanceof WP_Post?(int)$candidate->ID:wp_insert_post(['post_title'=>'File Transfer','post_name'=>'file-transfer','post_content'=>'[sabri_file_transfer]','post_status'=>'publish','post_type'=>'page','comment_status'=>'closed'],true);if(is_wp_error($created))return 0;$id=(int)$created;if($id>0){update_post_meta($id,self::PAGE_OWNER_META,'file-transfer');update_option('sn_file_transfer_page_id',$id,false);}return$id;}\n",
    "    public static function ensure_page(bool $repair): int{$id=(int)get_option('sn_file_transfer_page_id');$page=$id?get_post($id):null;if($page instanceof WP_Post&&(string)get_post_meta($id,self::PAGE_OWNER_META,true)==='file-transfer'){if($repair||!has_shortcode((string)$page->post_content,'sabri_file_transfer')||$page->post_status!=='publish'){$updated=wp_update_post(['ID'=>$id,'post_title'=>'File Transfer','post_content'=>'[sabri_file_transfer]','post_status'=>'publish','comment_status'=>'closed'],true);if(is_wp_error($updated)||(int)$updated!==$id)return 0;$page=get_post($id);}if(!$page instanceof WP_Post||$page->post_status!=='publish'||!has_shortcode((string)$page->post_content,'sabri_file_transfer'))return 0;return$id;}$candidate=get_page_by_path('file-transfer',OBJECT,'page');if($candidate instanceof WP_Post&&(string)get_post_meta((int)$candidate->ID,self::PAGE_OWNER_META,true)!=='file-transfer')return 0;$created=$candidate instanceof WP_Post?(int)$candidate->ID:wp_insert_post(['post_title'=>'File Transfer','post_name'=>'file-transfer','post_content'=>'[sabri_file_transfer]','post_status'=>'publish','post_type'=>'page','comment_status'=>'closed'],true);if(is_wp_error($created))return 0;$id=(int)$created;if($id>0){update_post_meta($id,self::PAGE_OWNER_META,'file-transfer');update_option('sn_file_transfer_page_id',$id,false);}return$id;}\n",
    'transfer page repair'
)
replace_once(
    'sabri-network/includes/class-sn-smail-part-3.php',
    """        if ($page instanceof WP_Post && (string) get_post_meta($id, self::PAGE_OWNER_META, true) === 'smail') {
            if ($repair || !has_shortcode((string) $page->post_content, 'sabri_smail')) wp_update_post(['ID' => $id, 'post_title' => 'Smail', 'post_content' => '[sabri_smail]', 'post_status' => 'publish']);
            return $id;
        }
""",
    """        if ($page instanceof WP_Post && (string) get_post_meta($id, self::PAGE_OWNER_META, true) === 'smail') {
            if ($repair || !has_shortcode((string) $page->post_content, 'sabri_smail') || $page->post_status !== 'publish') {
                $updated = wp_update_post(['ID' => $id, 'post_title' => 'Smail', 'post_content' => '[sabri_smail]', 'post_status' => 'publish'], true);
                if (is_wp_error($updated) || (int) $updated !== $id) return 0;
                $page = get_post($id);
            }
            if (!$page instanceof WP_Post || $page->post_status !== 'publish' || !has_shortcode((string) $page->post_content, 'sabri_smail')) return 0;
            return $id;
        }
""",
    'smail page repair'
)

# R1-D02 repair workflow completeness.
replace_once(
    'sabri-network/includes/class-sn-admin.php',
    """        if (!SN_Private_Files::ensure_storage()) {
            wp_die(esc_html__('File 17 private storage could not be repaired safely.', 'sabri-network'), '', ['response' => 503]);
        }
        if (SN_Activator::ensure_network_page(true) <= 0) {
            wp_die(esc_html__('The File 17 Network page could not be repaired safely.', 'sabri-network'), '', ['response' => 503]);
        }
        SN_Messages::ensure_pages(true);
        SN_File_Transfer::ensure_page(true);
        SN_Smail::ensure_page(true);
""",
    """        if (!SN_Private_Files::ensure_storage()) {
            wp_die(esc_html__('File 17 private storage could not be repaired safely.', 'sabri-network'), '', ['response' => 503]);
        }
        if (!SN_File_Transfer::ensure_storage()) {
            wp_die(esc_html__('File 17 transfer storage could not be repaired safely.', 'sabri-network'), '', ['response' => 503]);
        }
        if (SN_Activator::ensure_network_page(true) <= 0) {
            wp_die(esc_html__('The File 17 Network page could not be repaired safely.', 'sabri-network'), '', ['response' => 503]);
        }
        $message_pages = SN_Messages::ensure_pages(true);
        if (($message_pages['messages'] ?? 0) <= 0 || ($message_pages['settings'] ?? 0) <= 0) {
            wp_die(esc_html__('The File 17 Messages pages could not be repaired safely.', 'sabri-network'), '', ['response' => 503]);
        }
        if (SN_File_Transfer::ensure_page(true) <= 0) {
            wp_die(esc_html__('The File 17 transfer page could not be repaired safely.', 'sabri-network'), '', ['response' => 503]);
        }
        if (SN_Smail::ensure_page(true) <= 0) {
            wp_die(esc_html__('The File 17 Smail page could not be repaired safely.', 'sabri-network'), '', ['response' => 503]);
        }
""",
    'admin repair completeness'
)

# R1-D03 fail-closed legacy OTP preservation reads.
replace_once(
    'sabri-network/includes/class-sn-fifth-fresh-migration-hardening.php',
    """        $legacy_exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($legacy))) === $legacy;
        $backup_exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($backup))) === $backup;
""",
    """        $wpdb->last_error = '';
        $legacy_probe = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($legacy)));
        if ($wpdb->last_error !== '') throw new RuntimeException('legacy_otp_presence_check_failed');
        $legacy_exists = (string)$legacy_probe === $legacy;
        $wpdb->last_error = '';
        $backup_probe = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($backup)));
        if ($wpdb->last_error !== '') throw new RuntimeException('legacy_otp_backup_check_failed');
        $backup_exists = (string)$backup_probe === $backup;
""",
    'legacy otp preservation reads'
)

# Permanent cumulative regression suite for this 20-round cycle.
test = Path('sabri-network/tests/next20-contracts.php')
if not test.exists():
    test.write_text("""<?php
/** Permanent cumulative contracts for the 6 September 2026 next-fresh 20-round cycle. */
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$activator=$read('includes/class-sn-activator.php');$admin=$read('includes/class-sn-admin.php');$messages=$read('includes/class-sn-messages.php');$transfer=$read('includes/class-sn-file-transfer-part-7.php');$smail=$read('includes/class-sn-smail-part-3.php');$migration=$read('includes/class-sn-fifth-fresh-migration-hardening.php');
$check(str_contains($activator,"wp_update_post([")&&str_contains($activator,"], true);"),'R1 network repair requests WP_Error-aware post update');
$check(str_contains($activator,"File 17 Messages pages could not be created safely."),'R1 activation verifies both Messages pages');
$check(str_contains($messages,"if (is_wp_error($updated) || (int) $updated !== $page_id) return 0;"),'R1 Messages repair fails closed on wp_update_post error');
$check(str_contains($transfer,"if(is_wp_error($updated)||(int)$updated!==$id)return 0"),'R1 transfer page repair fails closed');
$check(str_contains($smail,"$page->post_status !== 'publish'")&&str_contains($smail,"is_wp_error($updated)"),'R1 Smail page repair verifies published truth');
$check(str_contains($admin,'SN_File_Transfer::ensure_storage()'),'R1 complete repair verifies transfer storage');
$check(str_contains($admin,"$message_pages = SN_Messages::ensure_pages(true);")&&str_contains($admin,'SN_File_Transfer::ensure_page(true) <= 0')&&str_contains($admin,'SN_Smail::ensure_page(true) <= 0'),'R1 complete repair checks all owned page results');
$check(str_contains($migration,"legacy_otp_presence_check_failed")&&str_contains($migration,"legacy_otp_backup_check_failed")&&substr_count($migration,"$wpdb->last_error = '';")>=2,'R1 migration rejects failed legacy table probes');
if($fail){fwrite(STDERR,"Next20 contracts failed (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Next20 contracts: PASS ($checks checks)\n";
""",encoding='utf-8')

q=Path('sabri-network/tools/quality-check.sh'); s=q.read_text(encoding='utf-8')
if 'next20-contracts.php' not in s:
    needle="tests=(\n another-fresh-r6-spaces-contracts.php"
    if needle not in s: raise SystemExit('quality inventory insertion point missing')
    s=s.replace(needle,"tests=(\n next20-contracts.php another-fresh-r6-spaces-contracts.php",1)
    q.write_text(s,encoding='utf-8')
