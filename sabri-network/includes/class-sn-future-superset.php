<?php
/** File 17 Future Communication Superset — 24 Founder-approved enhancements. */
declare(strict_types=1);
defined('ABSPATH') || exit;
require_once SN_DIR . 'includes/class-sn-future-superset-part-1.php';
require_once SN_DIR . 'includes/class-sn-future-superset-part-2.php';
require_once SN_DIR . 'includes/class-sn-future-superset-part-3.php';
require_once SN_DIR . 'includes/class-sn-future-superset-core.php';

final class SN_Future_Superset {
    public const SCHEMA_VERSION='1.0.0';
    public const FEATURE_COUNT=24;
    private const MAX_PAYLOAD_BYTES=65535;
    private const MAX_SELECTED_MESSAGES=50;
    use SN_Future_Superset_Part_1;
    use SN_Future_Superset_Part_2;
    use SN_Future_Superset_Part_3;
    use SN_Future_Superset_Core;

    public static function features(): array { return [
        'F17-FUT-01'=>['slug'=>'audited-e2ee','phase'=>'advanced-trust','provider'=>'e2ee','label'=>'Audited E2EE Mode'],
        'F17-FUT-02'=>['slug'=>'device-key-verification','phase'=>'advanced-trust','provider'=>'','label'=>'Device Key Verification / Safety Numbers'],
        'F17-FUT-03'=>['slug'=>'key-transparency','phase'=>'advanced-trust','provider'=>'','label'=>'Key Transparency Log'],
        'F17-FUT-04'=>['slug'=>'conversation-lock','phase'=>'advanced-trust','provider'=>'step-up','label'=>'Sensitive Conversation Lock'],
        'F17-FUT-05'=>['slug'=>'team-inbox','phase'=>'next','provider'=>'','label'=>'Delegated / Shared Team Inbox'],
        'F17-FUT-06'=>['slug'=>'assignment-handoff','phase'=>'next','provider'=>'','label'=>'Conversation Assignment & Handoff'],
        'F17-FUT-07'=>['slug'=>'remind-later','phase'=>'next','provider'=>'file19','label'=>'Snooze / Remind Me Later'],
        'F17-FUT-08'=>['slug'=>'saved-replies','phase'=>'next','provider'=>'','label'=>'Saved Replies & Professional Templates'],
        'F17-FUT-09'=>['slug'=>'message-version-history','phase'=>'next','provider'=>'','label'=>'Advanced Message Version History'],
        'F17-FUT-10'=>['slug'=>'bulk-conversation-ops','phase'=>'next','provider'=>'','label'=>'Bulk Conversation Operations'],
        'F17-FUT-11'=>['slug'=>'smart-private-views','phase'=>'next','provider'=>'','label'=>'Saved Searches / Smart Private Views'],
        'F17-FUT-12'=>['slug'=>'expiring-qr-invites','phase'=>'next','provider'=>'','label'=>'Expiring QR Community Invitations'],
        'F17-FUT-13'=>['slug'=>'temporary-membership','phase'=>'next','provider'=>'','label'=>'Temporary Scoped Membership'],
        'F17-FUT-14'=>['slug'=>'mentor-student','phase'=>'next','provider'=>'','label'=>'Mentor–Student Communication Mode'],
        'F17-FUT-15'=>['slug'=>'scholarly-citations','phase'=>'next','provider'=>'file06-file12','label'=>'Scholarly Citation Cards'],
        'F17-FUT-16'=>['slug'=>'case-discussion','phase'=>'next','provider'=>'','label'=>'De-identified Case Discussion Template'],
        'F17-FUT-17'=>['slug'=>'call-lobby','phase'=>'scale','provider'=>'sfu','label'=>'Call Waiting Room / Lobby'],
        'F17-FUT-18'=>['slug'=>'hand-raise','phase'=>'scale','provider'=>'sfu','label'=>'Hand Raise & Speaker Queue'],
        'F17-FUT-19'=>['slug'=>'breakout-rooms','phase'=>'scale','provider'=>'sfu','label'=>'Breakout Rooms'],
        'F17-FUT-20'=>['slug'=>'host-transfer','phase'=>'scale','provider'=>'sfu','label'=>'Co-host / Host Transfer'],
        'F17-FUT-21'=>['slug'=>'network-quality','phase'=>'scale','provider'=>'','label'=>'Call Network Quality Assistant'],
        'F17-FUT-22'=>['slug'=>'ai-assistant','phase'=>'advanced-trust','provider'=>'file16','label'=>'Opt-in AI Conversation Assistant'],
        'F17-FUT-23'=>['slug'=>'semantic-search','phase'=>'advanced-trust','provider'=>'private-semantic','label'=>'Private Semantic Search'],
        'F17-FUT-24'=>['slug'=>'interop-gateway','phase'=>'advanced-trust','provider'=>'interop','label'=>'Standards-Based Interoperability Gateway'],
    ]; }

    private static function client(WP_REST_Request $request, int $user_id, string $purpose): string {
        $candidate = strtolower(trim((string) $request->get_param('client_id')));
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{7,63}$/', $candidate)) return $user_id . ':' . $purpose . ':' . $candidate;
        $params = $request->get_params(); unset($params['client_id']);
        $normalize = static function (&$value) use (&$normalize): void { if (!is_array($value)) return; if (!array_is_list($value)) ksort($value, SORT_STRING); foreach ($value as &$nested) $normalize($nested); unset($nested); };
        $normalize($params);
        $json = wp_json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $user_id . ':' . $purpose . ':auto-' . substr(hash('sha256', (string) $json), 0, 48);
    }

    public static function cleanup(): void {
        global $wpdb; $now = self::now();
        $stale = gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS);
        $wpdb->query($wpdb->prepare("UPDATE " . self::records_table() . " SET state='active',updated_at=%s,version=version+1 WHERE feature_id='F17-FUT-07' AND state='firing' AND updated_at<%s", $now, $stale));
        $due = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::records_table() . " WHERE feature_id='F17-FUT-07' AND state='active' AND expires_at<=%s ORDER BY id ASC LIMIT 200", $now));
        foreach (is_array($due) ? $due : [] as $record) {
            $data = self::decode($record); if (is_wp_error($data)) continue;
            $claimed = $wpdb->update(self::records_table(), ['state'=>'firing','updated_at'=>$now,'version'=>(int)$record->version+1], ['id'=>(int)$record->id,'state'=>'active','version'=>(int)$record->version]); if ($claimed !== 1) continue;
            try {
                do_action('sn_network_notification_requested', ['owner'=>'file-17','recipient_id'=>(int)$record->owner_id,'type'=>'communication_reminder','title'=>'Communication reminder','body'=>mb_substr(sanitize_text_field((string)($data['label']??'Reminder')),0,191),'entity_type'=>'conversation','entity_id'=>(int)$record->scope_id,'message_id'=>(int)($data['message_id']??0),'idempotency_key'=>'file17-future-reminder:' . (int)$record->id]);
                $wpdb->update(self::records_table(), ['state'=>'fired','updated_at'=>self::now(),'version'=>(int)$record->version+2], ['id'=>(int)$record->id,'state'=>'firing','version'=>(int)$record->version+1]);
            } catch (Throwable $error) {
                $wpdb->update(self::records_table(), ['state'=>'active','updated_at'=>self::now(),'version'=>(int)$record->version+2], ['id'=>(int)$record->id,'state'=>'firing','version'=>(int)$record->version+1]);
                SN_DB::audit('future_reminder_handoff_failed','future_record',(int)$record->id,'failure',['reason'=>$error->getMessage()],(int)$record->owner_id);
            }
        }
        self::process_bulk_jobs($now);
        $temp = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::records_table() . " WHERE feature_id='F17-FUT-13' AND state='active' AND expires_at<=%s ORDER BY id ASC LIMIT 200", $now));
        foreach (is_array($temp) ? $temp : [] as $record) self::expire_temp($record);
        $wpdb->query($wpdb->prepare("UPDATE " . self::records_table() . " SET state='expired',updated_at=%s,version=version+1 WHERE state='active' AND expires_at IS NOT NULL AND expires_at<%s AND feature_id NOT IN ('F17-FUT-07','F17-FUT-10','F17-FUT-13')", $now, $now));
    }

    public static function register(): void {
        add_action('init',[self::class,'maybe_upgrade'],28); add_action('rest_api_init',[self::class,'register_routes'],1700); add_action('sn_cleanup_hourly',[self::class,'cleanup']);
        add_filter('rest_pre_dispatch',[self::class,'pre_dispatch'],8,3); add_filter('rest_post_dispatch',[self::class,'post_dispatch'],8,3);
        add_filter('wp_privacy_personal_data_exporters',[self::class,'register_exporter']); add_filter('wp_privacy_personal_data_erasers',[self::class,'register_eraser']);
        add_action('wp_enqueue_scripts',[self::class,'register_assets'],6); add_shortcode('sabri_communication_advanced',[self::class,'render_workspace']); add_action('sn_network_future_contract_request',[self::class,'emit_contract']);
    }
    public static function maybe_upgrade(): void { if((string)get_option('sn_future_superset_schema_version','')!==self::SCHEMA_VERSION) self::install(); }
    public static function install(): void {
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE ".self::records_table()." (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,feature_id VARCHAR(24) NOT NULL,owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,scope_type VARCHAR(24) NOT NULL DEFAULT 'user',scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0,state VARCHAR(24) NOT NULL DEFAULT 'active',payload_cipher LONGTEXT NULL,client_key CHAR(64) NULL DEFAULT NULL,expires_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,version BIGINT UNSIGNED NOT NULL DEFAULT 1,PRIMARY KEY(id),UNIQUE KEY client_key(client_key),KEY owner_feature(owner_id,feature_id,state,id),KEY scope_feature(scope_type,scope_id,feature_id,state,id),KEY expiry(state,expires_at)) $c;");
        dbDelta("CREATE TABLE ".self::device_keys_table()." (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NOT NULL,device_id VARCHAR(96) NOT NULL,algorithm VARCHAR(48) NOT NULL,public_key LONGTEXT NOT NULL,fingerprint CHAR(64) NOT NULL,state VARCHAR(24) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,revoked_at DATETIME NULL,PRIMARY KEY(id),UNIQUE KEY user_device(user_id,device_id),KEY user_state(user_id,state,id),KEY fingerprint(fingerprint)) $c;");
        dbDelta("CREATE TABLE ".self::key_log_table()." (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NOT NULL,device_id VARCHAR(96) NOT NULL,event VARCHAR(32) NOT NULL,fingerprint CHAR(64) NOT NULL,previous_fingerprint CHAR(64) NOT NULL DEFAULT '',entry_hash CHAR(64) NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(id),KEY user_created(user_id,id),KEY device_created(device_id,id)) $c;");
        dbDelta("CREATE TABLE ".self::versions_table()." (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,message_id BIGINT UNSIGNED NOT NULL,conversation_id BIGINT UNSIGNED NOT NULL,editor_id BIGINT UNSIGNED NOT NULL,revision BIGINT UNSIGNED NOT NULL,body_cipher LONGTEXT NOT NULL,source_hash CHAR(64) NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(id),UNIQUE KEY message_revision(message_id,revision),KEY conversation_message(conversation_id,message_id,id)) $c;");
        update_option('sn_future_superset_schema_version',self::SCHEMA_VERSION,false);
    }

    public static function register_routes(): void {
        $a=[SN_REST::class,'access'];
        self::route('/future/capabilities','GET','capabilities',$a); self::route('/future/e2ee-policy','POST','set_e2ee_policy',$a);
        register_rest_route('sabri-network/v2','/future/device-keys',[['methods'=>'GET','callback'=>[self::class,'list_device_keys'],'permission_callback'=>$a],['methods'=>'POST','callback'=>[self::class,'register_device_key'],'permission_callback'=>$a]]);
        self::route('/future/device-keys/(?P<user_id>\d+)/safety-number','GET','safety_number',$a); self::route('/future/key-transparency/(?P<user_id>\d+)','GET','key_transparency',$a);
        self::route('/future/conversation-locks/(?P<id>\d+)','POST','set_conversation_lock',$a); self::route('/future/team-inbox/(?P<id>\d+)','GET','get_team_inbox',$a); self::route('/future/team-inbox/(?P<id>\d+)','POST','set_team_inbox',$a); self::route('/future/team-inbox/(?P<id>\d+)/handoff','POST','handoff_team_inbox',$a);
        register_rest_route('sabri-network/v2','/future/reminders',[['methods'=>'GET','callback'=>[self::class,'list_reminders'],'permission_callback'=>$a],['methods'=>'POST','callback'=>[self::class,'create_reminder'],'permission_callback'=>$a]]);
        register_rest_route('sabri-network/v2','/future/templates',[['methods'=>'GET','callback'=>[self::class,'list_templates'],'permission_callback'=>$a],['methods'=>'POST','callback'=>[self::class,'save_template'],'permission_callback'=>$a]]);
        self::route('/future/messages/(?P<id>\d+)/versions','GET','message_versions',$a); self::route('/future/conversations/bulk','POST','bulk_conversations',$a);
        register_rest_route('sabri-network/v2','/future/conversations/bulk/(?P<id>\d+)',[['methods'=>'GET','callback'=>[self::class,'bulk_job_status'],'permission_callback'=>$a],['methods'=>'DELETE','callback'=>[self::class,'cancel_bulk_job'],'permission_callback'=>$a]]);
        register_rest_route('sabri-network/v2','/future/smart-views',[['methods'=>'GET','callback'=>[self::class,'list_smart_views'],'permission_callback'=>$a],['methods'=>'POST','callback'=>[self::class,'save_smart_view'],'permission_callback'=>$a]]);
        self::route('/future/community-invites','POST','create_qr_invite',$a); self::route('/future/community-invites/redeem','POST','redeem_qr_invite',$a); self::route('/future/temporary-memberships','POST','grant_temporary_membership',$a);
        register_rest_route('sabri-network/v2','/future/mentorships',[['methods'=>'GET','callback'=>[self::class,'list_mentorships'],'permission_callback'=>$a],['methods'=>'POST','callback'=>[self::class,'create_mentorship'],'permission_callback'=>$a]]); self::route('/future/mentorships/(?P<id>\d+)','POST','decide_mentorship',$a);
        self::route('/future/citations','POST','create_citation_card',$a); self::route('/future/case-discussions','POST','create_case_discussion',$a);
        self::route('/calls/(?P<id>\d+)/lobby','GET','call_lobby',$a); self::route('/calls/(?P<id>\d+)/lobby','POST','update_call_lobby',$a); self::route('/calls/(?P<id>\d+)/hand-raise','POST','hand_raise',$a); self::route('/calls/(?P<id>\d+)/breakouts','POST','create_breakouts',$a); self::route('/calls/(?P<id>\d+)/host-transfer','POST','transfer_call_host',$a); self::route('/calls/(?P<id>\d+)/network-quality','POST','network_quality',$a);
        self::route('/future/ai-assistant','POST','ai_assistant',$a); self::route('/future/semantic-search','POST','semantic_search',$a);
        register_rest_route('sabri-network/v2','/future/interop',[['methods'=>'GET','callback'=>[self::class,'list_interop_bridges'],'permission_callback'=>$a],['methods'=>'POST','callback'=>[self::class,'create_interop_bridge'],'permission_callback'=>$a]]);
        self::route('/future/records/(?P<id>\d+)','DELETE','delete_owned_record',$a);
    }
    private static function route(string $path,string $methods,string $callback,$permission): void { register_rest_route('sabri-network/v2',$path,['methods'=>$methods,'callback'=>[self::class,$callback],'permission_callback'=>$permission]); }
}
