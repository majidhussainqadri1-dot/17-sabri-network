<?php
/** Fifth fresh review: serialized, verified, retry-safe File-17 schema upgrade governor. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fifth_Fresh_Migration_Hardening {
    private const LOCK = 'sn:f17:schema-upgrade:v2.1';
    private const LOCK_TIMEOUT = 15;
    private const STATE_OPTION = 'sn_migration_state';

    public static function register(): void {
        // Run before module-level init/maybe_upgrade hooks so no partial schema state
        // can be published or used by a later File-17 runtime callback.
        add_action('init', [self::class, 'enforce'], -1000);
    }

    public static function enforce(): void {
        $result = self::upgrade(false);
        if (!is_wp_error($result)) return;
        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) status_header(503);
        wp_die(
            esc_html($result->get_error_message()),
            esc_html__('Sabri Network migration unavailable', 'sabri-network'),
            ['response'=>503]
        );
    }

    /** Run every repository-owned schema installer under one lock and publish version truth only after verification. */
    public static function upgrade(bool $force = false): bool|WP_Error {
        global $wpdb;
        if (!$force && (string)get_option('sn_plugin_version','') === SN_VERSION && self::verify_schema()) return true;
        $locked = (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', self::LOCK, self::LOCK_TIMEOUT));
        if ($locked !== 1) return new WP_Error('sn_migration_busy','File 17 schema upgrade is already running. Retry after it completes.',['status'=>503]);
        $snapshot = self::version_snapshot();
        $from = (string)get_option('sn_plugin_version','');
        update_option(self::STATE_OPTION, ['status'=>'running','from'=>$from,'to'=>SN_VERSION,'started_at'=>gmdate('c')], false);
        try {
            if (!$force && (string)get_option('sn_plugin_version','') === SN_VERSION && self::verify_schema()) return true;
            self::preserve_legacy_otp_table();
            foreach (self::installers() as [$class,$method]) {
                $wpdb->last_error = '';
                $result = $class::$method();
                if (is_wp_error($result)) throw new RuntimeException($class . '::' . $method . ':' . $result->get_error_code());
                if ($result === false) throw new RuntimeException($class . '::' . $method . ':returned_false');
                if ((string)$wpdb->last_error !== '') throw new RuntimeException($class . '::' . $method . ':' . $wpdb->last_error);
            }
            if (!self::verify_schema()) throw new RuntimeException('schema_verification_failed');
            update_option('sn_plugin_version', SN_VERSION, false);
            update_option(self::STATE_OPTION, [
                'status'=>'complete','from'=>$from,'to'=>SN_VERSION,'completed_at'=>gmdate('c'),
                'verification'=>'all-owned-tables-columns-and-critical-indexes-pass',
            ], false);
            return true;
        } catch (Throwable $e) {
            self::restore_version_snapshot($snapshot);
            update_option(self::STATE_OPTION, [
                'status'=>'failed','from'=>$from,'to'=>SN_VERSION,'failed_at'=>gmdate('c'),
                'reason'=>substr(sanitize_text_field($e->getMessage()),0,500),
            ], false);
            if (class_exists('SN_DB')) SN_DB::audit('schema_upgrade_failed','migration',0,'failure',['reason'=>$e->getMessage()],0);
            return new WP_Error('sn_migration_failed','File 17 schema upgrade did not pass post-migration verification and will be retried safely.',['status'=>503]);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
        }
    }

    /** Verify every runtime schema surface owned by the installers, plus critical idempotency/concurrency indexes. */
    public static function verify_schema(): bool {
        global $wpdb;
        $required = [
            SN_DB::table('conversations')=>['id','type','owner_id','direct_key','status'],
            SN_DB::table('members')=>['conversation_id','user_id','role','left_at'],
            SN_DB::table('messages')=>['conversation_id','sender_id','idempotency_key','body','metadata','deleted_at'],
            SN_DB::table('reactions')=>['message_id','user_id','reaction'],
            SN_DB::table('contacts')=>['user_id','contact_user_id','pair_key','status'],
            SN_DB::table('follows')=>['follower_id','followed_id','status','version'],
            SN_DB::table('updates')=>['user_id','body','privacy','expires_at'],
            SN_DB::table('update_views')=>['update_id','viewer_id','viewed_at'],
            SN_DB::table('calls')=>['conversation_id','call_type','status','active_key'],
            SN_DB::table('call_members')=>['call_id','user_id','status'],
            SN_DB::table('signals')=>['call_id','from_user_id','to_user_id','consumed_at'],
            SN_DB::table('presence')=>['user_id','status','expires_at'],
            SN_DB::table('typing')=>['conversation_id','user_id','expires_at'],
            SN_DB::table('notifications')=>['user_id','type','is_read'],
            SN_DB::table('blocks')=>['user_id','blocked_user_id'],
            SN_DB::table('reports')=>['message_id','legal_hold','appeal_status','version','target_type','target_ref','request_fingerprint','appeal_count'],
            SN_DB::table('attachments')=>['owner_id','storage_key','sha256','deleted_at'],
            SN_DB::table('rate_limits')=>['bucket','subject_hash','hits','expires_at'],
            SN_DB::table('audit_log')=>['actor_id','action','object_type','object_id'],
            SN_DB::table('step_up_grants')=>['grant_uuid','user_id','purpose','token_hash','status'],
            SN_DB::table('high_risk_actions')=>['action_uuid','action_type','requester_id','approver_id','executor_id','status','version'],
            SN_DB::table('spaces')=>['owner_user_id','conversation_id','type','visibility','state','version'],
            SN_DB::table('space_members')=>['space_id','user_id','role','status','version'],
            SN_DB::table('space_invites')=>['space_id','invitee_id','status','token_hash','version'],
            SN_DB::table('space_join_requests')=>['space_id','requester_id','status','version'],
            SN_DB::table('space_bans')=>['space_id','user_id','status','version'],
            SN_DB::table('space_governance')=>['space_id','actor_id','action','scope_hash'],
            SN_DB::table('presence_devices')=>['user_id','device_key','state','expires_at','revoked_at','version'],
            SN_DB::table('message_mentions')=>['message_id','conversation_id','mentioned_user_id'],
            SN_DB::table('message_pins')=>['conversation_id','message_id','pinned_by'],
            SN_DB::table('message_stars')=>['user_id','message_id'],
            SN_DB::table('message_folders')=>['user_id','slug','version'],
            SN_DB::table('message_folder_items')=>['folder_id','user_id','conversation_id'],
            SN_DB::table('message_hides')=>['user_id','message_id','hidden_at'],
            SN_DB::table('conversation_contexts')=>['conversation_id','provider','external_id','attached_by','version'],
            SN_DB::table('cf01_context_refs')=>['conversation_id','context_ref','issued_by','status','version'],
            SN_DB::table('conference_providers')=>['provider_key','provider_type','status','version'],
            SN_DB::table('message_receipts')=>['message_id','conversation_id','user_id','device_key','updated_at'],
            SN_DB::table('transfer_sessions')=>['public_id','sender_id','total_bytes','status','scan_status','version'],
            SN_DB::table('transfer_chunks')=>['transfer_id','chunk_index','storage_key','sha256'],
            SN_DB::table('transfer_recipients')=>['transfer_id','user_id','state','revoked_at'],
            SN_DB::table('smail_messages')=>['message_id','conversation_id','sender_id','client_key'],
            SN_DB::table('smail_states')=>['smail_message_id','user_id','updated_at'],
            SN_DB::table('smail_drafts')=>['public_id','owner_id','encrypted_payload','payload_hash','version'],
            SN_DB::table('message_search_tokens')=>['message_id','conversation_id','token_hash','key_epoch'],
            SN_DB::table('event_outbox')=>['event_uuid','event_key','event_type','status','version'],
            SN_DB::table('event_inbox')=>['producer','event_uuid','payload_hash','status'],
            $wpdb->prefix.'sn_meet_meetings'=>['public_id','host_id','conversation_id','status','idempotency_key','version'],
            $wpdb->prefix.'sn_meet_participants'=>['meeting_id','user_id','role','state','version'],
            $wpdb->prefix.'sn_meet_sessions'=>['meeting_id','user_id','session_hash','state'],
            $wpdb->prefix.'sn_meet_signals'=>['meeting_id','from_user_id','to_user_id','expires_at'],
            $wpdb->prefix.'sn_meet_events'=>['meeting_id','actor_id','event_type'],
            SN_DB::table('message_requests')=>['requester_id','recipient_id','pair_key','client_key','status','version'],
            SN_DB::table('scheduled_messages')=>['conversation_id','sender_id','client_key','status','deliver_at'],
            SN_DB::table('poll_votes')=>['message_id','user_id','option_index'],
            SN_DB::table('community_settings')=>['space_id','rules_version','updated_by'],
            SN_DB::table('community_artifacts')=>['space_id','type','author_id','status','version'],
            SN_DB::table('community_responses')=>['artifact_id','user_id','status','version'],
            SN_DB::table('two_plan_idempotency')=>['scope_key','actor_id','method','route_hash','request_hash','state'],
            $wpdb->prefix.'sn_future_records'=>['feature_id','owner_id','scope_type','scope_id','state','payload_cipher','client_key'],
            $wpdb->prefix.'sn_future_device_keys'=>['user_id','device_id','fingerprint','state','revoked_at'],
            $wpdb->prefix.'sn_future_key_log'=>['user_id','device_id','event','entry_hash'],
            $wpdb->prefix.'sn_future_message_versions'=>['message_id','conversation_id','editor_id','revision','body_cipher'],
        ];
        foreach ($required as $table=>$columns) {
            if (!self::table_has_columns((string)$table,$columns)) return false;
        }

        $indexes = [
            [SN_DB::table('conversations'),'direct_key',true],
            [SN_DB::table('members'),'conversation_user',true],
            [SN_DB::table('messages'),'idempotency_key',true],
            [SN_DB::table('contacts'),'pair_key',true],
            [SN_DB::table('follows'),'follower_followed',true],
            [SN_DB::table('calls'),'active_key',true],
            [SN_DB::table('call_members'),'call_user',true],
            [SN_DB::table('blocks'),'user_blocked',true],
            [SN_DB::table('reports'),'reporter_client',true],
            [SN_DB::table('reports'),'target_ref_created',false],
            [SN_DB::table('rate_limits'),'bucket_subject',true],
            [SN_DB::table('step_up_grants'),'grant_uuid',true],
            [SN_DB::table('step_up_grants'),'token_hash',true],
            [SN_DB::table('high_risk_actions'),'action_uuid',true],
            [SN_DB::table('spaces'),'public_id',true],
            [SN_DB::table('spaces'),'slug',true],
            [SN_DB::table('space_members'),'space_user',true],
            [SN_DB::table('space_invites'),'active_key',true],
            [SN_DB::table('space_join_requests'),'active_key',true],
            [SN_DB::table('space_bans'),'space_user',true],
            [SN_DB::table('message_receipts'),'message_user_device',true],
            [SN_DB::table('event_outbox'),'event_key',true],
            [SN_DB::table('event_outbox'),'event_uuid',true],
            [SN_DB::table('event_inbox'),'producer_event',true],
            [$wpdb->prefix.'sn_meet_meetings','public_id',true],
            [$wpdb->prefix.'sn_meet_meetings','host_request',true],
            [$wpdb->prefix.'sn_meet_participants','meeting_user',true],
            [$wpdb->prefix.'sn_meet_sessions','meeting_session',true],
            [SN_DB::table('message_requests'),'client_key',true],
            [SN_DB::table('scheduled_messages'),'client_key',true],
            [SN_DB::table('poll_votes'),'message_user',true],
            [$wpdb->prefix.'sn_future_records','client_key',true],
            [$wpdb->prefix.'sn_future_device_keys','user_device',true],
            [$wpdb->prefix.'sn_future_message_versions','message_revision',true],
        ];
        foreach ($indexes as [$table,$index,$unique]) {
            if (!self::index_matches((string)$table,(string)$index,(bool)$unique)) return false;
        }
        return true;
    }

    private static function installers(): array {
        return [
            [SN_DB::class,'install'], [SN_High_Risk::class,'install'], [SN_Spaces::class,'install'],
            [SN_Presence_Devices::class,'install'], [SN_Message_Operations::class,'install'],
            [SN_Context_Adapters::class,'install'], [SN_CF01_Clinical_Context::class,'install'],
            [SN_Conference_Provider::class,'install'], [SN_Messages::class,'install'],
            [SN_File_Transfer::class,'install'], [SN_Smail::class,'install'], [SN_Message_Search::class,'install'],
            [SN_Outbox::class,'install'], [SN_Meet::class,'install'], [SN_Two_Plan_Completion::class,'install'],
            [SN_Two_Plan_Contract_Firewall::class,'install'], [SN_Future_Superset::class,'install'],
            [self::class,'install_r14_schema'],
        ];
    }

    /** Bring the late R14 reports schema inside the same migration lock and fail-closed completion truth. */
    private static function install_r14_schema(): bool|WP_Error {
        if (!self::verify_r14_schema()) delete_option('sn_r14_safety_schema_version');
        SN_Seventh_Fresh_R14_Hardening::maybe_upgrade_schema();
        return self::verify_r14_schema()
            ? true
            : new WP_Error('sn_r14_schema_incomplete','The R14 report-safety schema did not verify.',['status'=>503]);
    }

    private static function verify_r14_schema(): bool {
        global $wpdb;
        $table=SN_DB::table('reports');
        return self::table_has_columns($table,['target_type','target_ref','request_fingerprint','appeal_count'])
            && self::index_matches($table,'target_ref_created',false);
    }

    private static function table_has_columns(string $table,array $columns): bool {
        global $wpdb;
        $exists=(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
        if($exists!==$table)return false;
        $actual=array_map('strval',$wpdb->get_col('SHOW COLUMNS FROM `'.esc_sql($table).'`',0)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach($columns as $column)if(!in_array((string)$column,$actual,true))return false;
        return true;
    }

    private static function index_matches(string $table,string $index,bool $unique): bool {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SHOW INDEX FROM `'.esc_sql($table).'` WHERE Key_name=%s LIMIT 1',$index)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if(!$row)return false;
        return !$unique || (int)$row->Non_unique===0;
    }

    /** Preserve legacy File-17 OTP data for rollback evidence before the old installer retires its table. */
    private static function preserve_legacy_otp_table(): void {
        global $wpdb;
        $legacy = $wpdb->prefix . 'sn_phone_otps';
        $backup = $wpdb->prefix . 'sn_phone_otps_f17_retired';
        $legacy_exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($legacy))) === $legacy;
        $backup_exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($backup))) === $backup;
        if ($legacy_exists && !$backup_exists) {
            $ok = $wpdb->query('RENAME TABLE `' . esc_sql($legacy) . '` TO `' . esc_sql($backup) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ($ok === false) throw new RuntimeException('legacy_otp_preservation_failed');
        }
    }

    private static function version_snapshot(): array {
        $keys = [
            'sn_plugin_version','sn_db_version','sn_high_risk_schema_version','sn_spaces_schema_version',
            'sn_presence_devices_schema_version','sn_message_operations_schema_version','sn_context_adapters_schema_version',
            'sn_cf01_context_schema_version','sn_conference_provider_schema_version','sn_messages_schema_version','sn_message_receipts_schema_version',
            'sn_file_transfer_schema_version','sn_smail_schema_version','sn_message_search_schema_version',
            'sn_event_delivery_schema_version','sn_meet_db_version','sn_meet_schema_version','sn_two_plan_schema_version','sn_two_plan_firewall_schema_version','sn_future_schema_version',
            'sn_future_superset_schema_version','sn_central_plan_schema_version','sn_r14_safety_schema_version',
        ];
        $sentinel = new stdClass();
        $out=[];
        foreach($keys as $key){$value=get_option($key,$sentinel);$out[$key]=['exists'=>$value!==$sentinel,'value'=>$value!==$sentinel?$value:null];}
        return $out;
    }

    private static function restore_version_snapshot(array $snapshot): void {
        foreach($snapshot as $key=>$state){
            if(!empty($state['exists'])) update_option((string)$key,$state['value'],false);
            else delete_option((string)$key);
        }
    }
}
