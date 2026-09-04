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
        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            status_header(503);
        }
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
            if (!$force && (string)get_option('sn_plugin_version','') === SN_VERSION && self::verify_schema()) {
                // Another request may have completed the migration while this request
                // waited for the global lock. Never leave operational migration truth
                // stuck at "running" on this verified post-lock fast path.
                update_option(self::STATE_OPTION, [
                    'status'=>'complete','from'=>$from,'to'=>SN_VERSION,'completed_at'=>gmdate('c'),
                    'verification'=>'all-governed-installer-tables-plus-critical-columns-pass',
                    'completion_path'=>'post-lock-fast-path',
                ], false);
                return true;
            }
            self::preserve_legacy_otp_table();
            foreach (self::installers() as [$class,$method]) {
                $wpdb->last_error = '';
                $class::$method();
                if ((string)$wpdb->last_error !== '') throw new RuntimeException($class . '::' . $method . ':' . $wpdb->last_error);
            }
            if (!self::verify_schema()) throw new RuntimeException('schema_verification_failed');
            update_option('sn_plugin_version', SN_VERSION, false);
            update_option(self::STATE_OPTION, [
                'status'=>'complete','from'=>$from,'to'=>SN_VERSION,'completed_at'=>gmdate('c'),
                'verification'=>'all-governed-installer-tables-plus-critical-columns-pass',
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

    public static function verify_schema(): bool {
        global $wpdb;

        // Table existence is verified for every surface created by the governed
        // installer list. Installer-local version options and the final value of
        // $wpdb->last_error are not accepted as proof that earlier dbDelta statements
        // succeeded: a later successful query can otherwise mask a missing table.
        $required_tables = [
            // SN_DB core.
            'conversations','members','messages','reactions','contacts','follows','updates','update_views',
            'calls','call_members','signals','presence','typing','notifications','blocks','reports','attachments','rate_limits','audit_log',
            // High-risk, spaces and presence devices. The spaces audit owner is
            // SN_Spaces::audit_table() => SN_DB::table('space_governance').
            'step_up_grants','high_risk_actions','spaces','space_members','space_invites','space_join_requests','space_bans','space_governance','presence_devices',
            // Message organization, context, provider and receipts.
            'message_mentions','message_pins','message_stars','message_folders','message_folder_items','message_hides',
            'conversation_contexts','cf01_context_refs','conference_providers','message_receipts',
            // File transfer, Smail and private search/event delivery.
            'transfer_sessions','transfer_chunks','transfer_recipients','smail_messages','smail_states','smail_drafts',
            'message_search_tokens','event_outbox','event_inbox',
            // Sabri Meet uses the sn_meet_* physical namespace; SN_DB::table maps
            // these logical names to that exact prefix.
            'meet_meetings','meet_participants','meet_sessions','meet_signals','meet_events',
            // Two-plan completion.
            'message_requests','scheduled_messages','poll_votes','community_settings','community_artifacts','community_responses','two_plan_idempotency',
            // Future-24 superset.
            'future_records','future_device_keys','future_key_log','future_message_versions',
        ];
        foreach ($required_tables as $name) {
            $table = SN_DB::table($name);
            $exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($exists !== $table) return false;
        }

        // Mutation-sensitive tables additionally require exact critical columns. This
        // preserves the earlier fail-closed column gate while the table gate above
        // expands completion truth to the whole governed installer surface.
        $required = [
            'conversations'=>['id','type','owner_id','direct_key','status'],
            'members'=>['conversation_id','user_id','role','left_at'],
            'messages'=>['conversation_id','sender_id','body','metadata','deleted_at'],
            'reports'=>['message_id','legal_hold','appeal_status','version'],
            'calls'=>['conversation_id','call_type','status','active_key'],
            'call_members'=>['call_id','user_id','status'],
            'spaces'=>['owner_user_id','conversation_id','type','visibility','state','version'],
            'space_members'=>['space_id','user_id','role','status','version'],
            'space_invites'=>['space_id','invitee_id','status','token_hash','version'],
            'space_join_requests'=>['space_id','requester_id','status','version'],
            'presence_devices'=>['user_id','device_key','state','expires_at','revoked_at','version'],
            'step_up_grants'=>['grant_uuid','user_id','purpose','token_hash','status','expires_at','version'],
            'high_risk_actions'=>['action_type','requester_id','approver_id','executor_id','status','version'],
            'conference_providers'=>['provider_key','provider_type','status','version'],
            'transfer_sessions'=>['public_id','sender_id','total_bytes','status','scan_status','version'],
            'transfer_chunks'=>['transfer_id','chunk_index','storage_key','sha256'],
            'transfer_recipients'=>['transfer_id','user_id','state','revoked_at'],
            'smail_messages'=>['message_id','conversation_id','sender_id','client_key'],
            'smail_states'=>['smail_message_id','user_id','updated_at'],
            'smail_drafts'=>['public_id','owner_id','encrypted_payload','payload_hash','version'],
            'future_records'=>['feature_id','owner_id','scope_type','scope_id','state','payload_cipher'],
            'conversation_contexts'=>['conversation_id','provider','external_id','attached_by','version'],
            'cf01_context_refs'=>['conversation_id','reference_uuid','issued_by','status','version'],
            'message_receipts'=>['message_id','conversation_id','user_id','device_key','updated_at'],
            'message_search_tokens'=>['message_id','conversation_id','sender_id','token_hash'],
            'event_outbox'=>['event_uuid','event_key','event_type','status','attempts','version'],
            'event_inbox'=>['producer','event_uuid','payload_hash','status','attempts'],
            'meet_meetings'=>['public_id','host_id','conversation_id','status','version'],
            'meet_participants'=>['meeting_id','user_id','role','state','version'],
            'message_requests'=>['requester_id','recipient_id','status','version'],
            'scheduled_messages'=>['conversation_id','sender_id','deliver_at','status'],
            'community_artifacts'=>['space_id','type','author_id','status','version'],
            'two_plan_idempotency'=>['scope_key','actor_id','method','route_hash','request_hash','state','response_code','response_cipher','updated_at'],
            'future_device_keys'=>['user_id','device_id','fingerprint','state'],
            'future_message_versions'=>['message_id','conversation_id','editor_id','revision','body_cipher'],
        ];
        foreach ($required as $name=>$columns) {
            $table = SN_DB::table($name);
            $actual = array_map('strval', $wpdb->get_col('SHOW COLUMNS FROM `' . esc_sql($table) . '`', 0)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            foreach ($columns as $column) if (!in_array($column,$actual,true)) return false;
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
        ];
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
            'sn_cf01_context_schema_version','sn_conference_provider_schema_version','sn_message_receipts_schema_version',
            'sn_file_transfer_schema_version','sn_smail_schema_version','sn_message_search_schema_version',
            'sn_event_delivery_schema_version','sn_meet_db_version','sn_meet_schema_version','sn_two_plan_schema_version','sn_two_plan_firewall_schema_version','sn_future_schema_version',
            'sn_future_superset_schema_version','sn_central_plan_schema_version',
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
