<?php
/**
 * File 17-owned opaque communication-context references for CF-01.
 *
 * This registry never copies message bodies, attachments, call payloads or
 * transcripts into a clinical domain. It issues revocable opaque references
 * and revalidates File 17 conversation state at every read and resolution.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_CF01_Clinical_Context {
    public const CONTRACT_NAME = 'sn.cf01.communication-context';
    public const CONTRACT_VERSION = '1.0.0';
    private const SCHEMA_VERSION = '1.0.0';
    private const ASSERTION_TTL = 60;
    private const DEFAULT_REFERENCE_TTL = 2592000;
    private const MAX_REFERENCE_TTL = 15552000;
    private const PURPOSES = [
        'care_coordination',
        'follow_up_reference',
        'patient_requested_context',
        'clinician_authored_summary_source',
    ];
    private const RETENTION_CLASSES = [
        'communication_standard',
        'communication_extended',
        'legal_hold',
    ];

    public static function register(): void {
        add_action('sn_cleanup_hourly', [self::class, 'cleanup']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference_uuid CHAR(36) NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            issued_by BIGINT UNSIGNED NOT NULL,
            purpose VARCHAR(80) NOT NULL,
            consent_hash CHAR(64) NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            retention_class VARCHAR(40) NOT NULL DEFAULT 'communication_standard',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY reference_uuid (reference_uuid),
            UNIQUE KEY issued_idempotency (issued_by,idempotency_key),
            KEY conversation_status (conversation_id,status,expires_at),
            KEY issuer_status (issued_by,status,updated_at)
        ) $charset;");
        update_option('sn_cf01_context_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if ((string) get_option('sn_cf01_context_schema_version', '') !== self::SCHEMA_VERSION) {
            self::install();
        }
    }

    /** @return array<string,mixed>|WP_Error */
    public static function issue_reference(int $conversation_id, int $actor_id, array $context): array|WP_Error {
        global $wpdb;
        if ($conversation_id <= 0 || $actor_id <= 0 || !SN_DB::is_member($conversation_id, $actor_id)) {
            return self::not_found();
        }
        $conversation = self::conversation($conversation_id);
        if (!$conversation || (string) $conversation->status !== 'active' || self::direct_conversation_blocked($conversation, $actor_id)) {
            return self::not_found();
        }
        $purpose = sanitize_key((string) ($context['purpose'] ?? ''));
        if (!in_array($purpose, self::PURPOSES, true)) {
            return self::error('sn_cf01_purpose_invalid', 'Select an approved clinical-context reference purpose.', 400);
        }
        $consent_reference = self::opaque_value((string) ($context['consent_reference'] ?? ''));
        $idempotency = self::opaque_value((string) ($context['idempotency_key'] ?? ''));
        if ($consent_reference === '') {
            return self::error('sn_cf01_consent_reference_required', 'An opaque consent reference is required.', 400);
        }
        if ($idempotency === '') {
            return self::error('sn_cf01_idempotency_required', 'An idempotency key is required.', 400);
        }
        if (apply_filters('sn_cf01_clinical_context_issuer_authorized', false, $actor_id, $conversation_id, $purpose, $context) !== true) {
            return self::error('sn_cf01_issuer_not_authorized', 'The clinical-context issuer is not authorized.', 403);
        }
        if (apply_filters('sn_cf01_clinical_context_consent_authorized', false, $actor_id, $conversation_id, $purpose, $consent_reference, $context) !== true) {
            return self::error('sn_cf01_consent_not_authorized', 'The clinical-context consent could not be verified.', 403);
        }

        $ttl = max(300, min(self::MAX_REFERENCE_TTL, absint($context['ttl_seconds'] ?? self::DEFAULT_REFERENCE_TTL)));
        $expires_at = gmdate('Y-m-d H:i:s', time() + $ttl);
        $retention_class = self::retention_class((string) apply_filters(
            'sn_cf01_clinical_context_retention_class',
            'communication_standard',
            $actor_id,
            $conversation_id,
            $purpose,
            $context
        ));
        $idempotency_hash = self::keyed_hash($actor_id . '|' . $idempotency, 'idempotency');
        $consent_hash = self::keyed_hash($consent_reference, 'consent');
        $now = self::now();

        if ($wpdb->query('START TRANSACTION') === false) {
            return self::error('sn_cf01_reference_issue_failed', 'The opaque clinical-context reference transaction could not start.', 500);
        }
        try {
            $locked_conversation = $wpdb->get_row($wpdb->prepare(
                'SELECT id,type,owner_id,privacy,status,updated_at FROM ' . SN_DB::table('conversations') . ' WHERE id=%d FOR UPDATE',
                $conversation_id
            ));
            $locked_member = $wpdb->get_row($wpdb->prepare(
                'SELECT id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id=%d AND left_at IS NULL LIMIT 1 FOR UPDATE',
                $conversation_id,
                $actor_id
            ));
            if (!$locked_conversation || !$locked_member || (string) $locked_conversation->status !== 'active' || self::direct_conversation_blocked($locked_conversation, $actor_id)) {
                $wpdb->query('ROLLBACK');
                return self::not_found();
            }
            SN_Membership_Assertions::clear_cache($actor_id);
            $access = SN_Policy::access();
            if (is_wp_error($access)) {
                $wpdb->query('ROLLBACK');
                return $access;
            }
            if (apply_filters('sn_cf01_clinical_context_issuer_authorized', false, $actor_id, $conversation_id, $purpose, $context) !== true) {
                $wpdb->query('ROLLBACK');
                return self::error('sn_cf01_issuer_not_authorized', 'The clinical-context issuer is no longer authorized.', 403);
            }
            if (apply_filters('sn_cf01_clinical_context_consent_authorized', false, $actor_id, $conversation_id, $purpose, $consent_reference, $context) !== true) {
                $wpdb->query('ROLLBACK');
                return self::error('sn_cf01_consent_not_authorized', 'The clinical-context consent is no longer current.', 403);
            }
            $conversation = $locked_conversation;
            $existing = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE issued_by=%d AND idempotency_key=%s FOR UPDATE',
                $actor_id,
                $idempotency_hash
            ));
            if ($existing) {
                if ((int) $existing->conversation_id !== $conversation_id
                    || !hash_equals((string) $existing->consent_hash, $consent_hash)
                    || (string) $existing->purpose !== $purpose) {
                    throw new RuntimeException('idempotency_scope_mismatch');
                }
                if ((string) $existing->status !== 'active' || self::timestamp((string) $existing->expires_at) <= time()) {
                    throw new RuntimeException('idempotent_reference_inactive');
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new RuntimeException('reference_read_commit_failed');
                }
                return self::issuance_receipt($existing);
            }

            $reference_uuid = strtolower(wp_generate_uuid4());
            $inserted = $wpdb->insert(self::table(), [
                'reference_uuid' => $reference_uuid,
                'conversation_id' => $conversation_id,
                'issued_by' => $actor_id,
                'purpose' => $purpose,
                'consent_hash' => $consent_hash,
                'idempotency_key' => $idempotency_hash,
                'retention_class' => $retention_class,
                'status' => 'active',
                'expires_at' => $expires_at,
                'created_at' => $now,
                'updated_at' => $now,
                'version' => 1,
            ]);
            if ($inserted === false) {
                throw new RuntimeException('reference_insert_failed');
            }
            $event = SN_Outbox::enqueue(
                'conversation.clinical_context_reference_issued',
                'conversation',
                $conversation_id,
                [
                    'reference_uuid' => $reference_uuid,
                    'purpose' => $purpose,
                    'expires_at' => $expires_at,
                    'conversation_state_hash' => self::conversation_state_hash($conversation, self::participant_count($conversation_id)),
                    'contains_message_body' => false,
                    'contains_attachment' => false,
                    'contains_call_transcript' => false,
                ],
                'conversation.clinical_context_reference_issued:' . $reference_uuid
            );
            if (is_wp_error($event)) {
                throw new RuntimeException($event->get_error_code());
            }
            SN_DB::audit('cf01_clinical_context_reference_issued', 'conversation', $conversation_id, 'success', [
                'reference_uuid' => $reference_uuid,
                'purpose' => $purpose,
                'consent_hash' => $consent_hash,
            ], $actor_id);
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('reference_commit_failed');
            }
            return self::issuance_receipt((object) [
                'reference_uuid' => $reference_uuid,
                'version' => 1,
                'purpose' => $purpose,
                'status' => 'active',
                'expires_at' => $expires_at,
                'retention_class' => $retention_class,
            ]);
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            $code = $exception->getMessage() === 'idempotency_scope_mismatch'
                ? 'sn_cf01_idempotency_scope_mismatch'
                : 'sn_cf01_reference_issue_failed';
            return self::error($code, 'The opaque clinical-context reference could not be issued.', $code === 'sn_cf01_idempotency_scope_mismatch' ? 409 : 500);
        }
    }

    /** @return array<string,mixed>|WP_Error */
    public static function assertion(string $reference_uuid, int $actor_id, array $context = []): array|WP_Error {
        global $wpdb;
        $reference_uuid = strtolower(trim($reference_uuid));
        if (!self::valid_uuid($reference_uuid) || $actor_id <= 0) {
            return self::not_found();
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE reference_uuid=%s', $reference_uuid));
        if (!$row || (string) $row->status !== 'active' || self::timestamp((string) $row->expires_at) <= time()) {
            return self::not_found();
        }
        $conversation_id = (int) $row->conversation_id;
        if (!SN_DB::is_member($conversation_id, $actor_id)) {
            return self::not_found();
        }
        $conversation = self::conversation($conversation_id);
        if (!$conversation || (string) $conversation->status !== 'active' || self::direct_conversation_blocked($conversation, $actor_id)) {
            return self::not_found();
        }
        $requested_purpose = sanitize_key((string) ($context['purpose'] ?? (string) $row->purpose));
        if ($requested_purpose !== (string) $row->purpose) {
            return self::error('sn_cf01_reference_purpose_mismatch', 'The reference purpose does not match.', 403);
        }
        if (apply_filters('sn_cf01_clinical_context_read_authorized', false, $actor_id, $conversation_id, $reference_uuid, $requested_purpose, $context) !== true) {
            return self::error('sn_cf01_reference_read_not_authorized', 'The clinical-context reference is not authorized for this request.', 403);
        }

        $participants = self::participant_count($conversation_id);
        $now = time();
        return [
            'contract' => self::CONTRACT_NAME,
            'contract_version' => self::CONTRACT_VERSION,
            'producer_version' => defined('SN_VERSION') ? SN_VERSION : '',
            'issued_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + self::ASSERTION_TTL),
            'reference' => [
                'reference_uuid' => $reference_uuid,
                'reference_version' => (int) $row->version,
                'purpose' => (string) $row->purpose,
                'status' => (string) $row->status,
                'reference_expires_at' => self::iso_time((string) $row->expires_at),
                'retention_class' => (string) $row->retention_class,
                'consent_verified' => true,
            ],
            'communication_context' => [
                'owner' => 'File 17',
                'conversation_type' => sanitize_key((string) $conversation->type),
                'visibility_class' => sanitize_key((string) $conversation->privacy),
                'state' => sanitize_key((string) $conversation->status),
                'state_version' => self::conversation_state_hash($conversation, $participants),
                'participant_count' => $participants,
                'actor_participant_class' => self::participant_class($conversation_id, $actor_id),
                'owner_reference' => self::subject_reference((int) $conversation->owner_id),
                'updated_at' => self::iso_time((string) $conversation->updated_at),
            ],
            'destination_intent' => self::destination_intent($reference_uuid),
            'content_boundary' => self::content_boundary(),
            'authorization_boundary' => self::authorization_boundary(),
            'result' => 'valid',
            'reason_code' => 'communication_context_reference_current',
        ];
    }

    /** @return array<string,mixed>|WP_Error */
    public static function resolve_destination(string $reference_uuid, int $actor_id, array $context = []): array|WP_Error {
        global $wpdb;
        $assertion = self::assertion($reference_uuid, $actor_id, $context);
        if (is_wp_error($assertion)) {
            return $assertion;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT conversation_id FROM ' . self::table() . ' WHERE reference_uuid=%s AND status=%s',
            strtolower($reference_uuid),
            'active'
        ));
        if (!$row || !SN_DB::is_member((int) $row->conversation_id, $actor_id)) {
            return self::not_found();
        }
        $url = add_query_arg('conversation', (int) $row->conversation_id, SN_Messages::messages_url());
        if (!self::same_origin_https($url)) {
            return self::error('sn_cf01_destination_invalid', 'The File 17 destination is unavailable.', 503);
        }
        return [
            'url' => $url,
            'reference_uuid' => strtolower($reference_uuid),
            'authorization_rechecked' => true,
            'bearer_authorization' => false,
            'cache_control' => 'private, no-store',
        ];
    }

    /** @return array<string,mixed>|WP_Error */
    public static function revoke_reference(string $reference_uuid, int $actor_id, string $reason = ''): array|WP_Error {
        global $wpdb;
        $reference_uuid = strtolower(trim($reference_uuid));
        $row = self::valid_uuid($reference_uuid)
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE reference_uuid=%s', $reference_uuid))
            : null;
        if (!$row || $actor_id <= 0) {
            return self::not_found();
        }
        $authorized = $actor_id === (int) $row->issued_by
            || apply_filters('sn_cf01_clinical_context_revoke_authorized', false, $actor_id, (int) $row->conversation_id, $reference_uuid, $reason) === true;
        if (!$authorized) {
            return self::not_found();
        }
        if ((string) $row->status !== 'active') {
            return ['status' => (string) $row->status, 'reference_uuid' => $reference_uuid, 'version' => (int) $row->version];
        }
        $now = self::now();
        $changed = $wpdb->update(self::table(), [
            'status' => 'revoked',
            'revoked_at' => $now,
            'updated_at' => $now,
            'version' => (int) $row->version + 1,
        ], [
            'id' => (int) $row->id,
            'status' => 'active',
            'version' => (int) $row->version,
        ]);
        if ($changed !== 1) {
            return self::error('sn_cf01_reference_revoke_conflict', 'The reference changed before it could be revoked.', 409);
        }
        SN_DB::audit('cf01_clinical_context_reference_revoked', 'conversation', (int) $row->conversation_id, 'success', [
            'reference_uuid' => $reference_uuid,
            'reason_code' => sanitize_key($reason),
        ], $actor_id);
        return ['status' => 'revoked', 'reference_uuid' => $reference_uuid, 'version' => (int) $row->version + 1];
    }

    public static function contract(): array {
        return [
            'contract' => self::CONTRACT_NAME,
            'contract_version' => self::CONTRACT_VERSION,
            'owner' => 'File 17',
            'writes_clinical_data' => false,
            'copies_message_content' => false,
            'copies_attachments' => false,
            'copies_call_content' => false,
            'events_are_authorization' => false,
            'chat_membership_is_treating_relationship' => false,
            'destination_is_bearer_authorization' => false,
            'requires' => [
                'active_file17_membership',
                'external_professional_issuer_authorization',
                'external_consent_authorization',
                'click_time_authentication_and_file17_authorization',
                'cf01_action_time_clinical_authorization',
            ],
        ];
    }

    public static function cleanup(): void {
        global $wpdb;
        $now = self::now();
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status='expired',updated_at=%s,version=version+1 WHERE status='active' AND expires_at<=%s LIMIT 500",
            $now,
            $now
        ));
    }

    public static function register_exporter(array $exporters): array {
        $exporters['sabri-network-cf01-references'] = [
            'exporter_friendly_name' => __('Communication-context references', 'sabri-network'),
            'callback' => [self::class, 'export_data'],
        ];
        return $exporters;
    }

    public static function register_eraser(array $erasers): array {
        $erasers['sabri-network-cf01-references'] = [
            'eraser_friendly_name' => __('Communication-context references', 'sabri-network'),
            'callback' => [self::class, 'erase_data'],
        ];
        return $erasers;
    }

    public static function export_data(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }
        $limit = 100;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT reference_uuid,purpose,retention_class,status,expires_at,created_at FROM ' . self::table() . ' WHERE issued_by=%d ORDER BY id ASC LIMIT %d OFFSET %d',
            (int) $user->ID,
            $limit,
            max(0, $page - 1) * $limit
        ));
        $data = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $data[] = [
                'group_id' => 'sabri-network-cf01-references',
                'group_label' => __('Communication-context references', 'sabri-network'),
                'item_id' => 'reference-' . (string) $row->reference_uuid,
                'data' => [
                    ['name' => __('Reference', 'sabri-network'), 'value' => (string) $row->reference_uuid],
                    ['name' => __('Purpose', 'sabri-network'), 'value' => (string) $row->purpose],
                    ['name' => __('Retention class', 'sabri-network'), 'value' => (string) $row->retention_class],
                    ['name' => __('Status', 'sabri-network'), 'value' => (string) $row->status],
                    ['name' => __('Expires', 'sabri-network'), 'value' => (string) $row->expires_at],
                    ['name' => __('Created', 'sabri-network'), 'value' => (string) $row->created_at],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($rows) < $limit];
    }

    public static function erase_data(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $now = self::now();
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status='revoked',revoked_at=%s,updated_at=%s,version=version+1 WHERE issued_by=%d AND status='active'",
            $now,
            $now,
            (int) $user->ID
        ));
        return [
            'items_removed' => $changed > 0,
            'items_retained' => true,
            'messages' => [__('Opaque references were revoked; minimal audit evidence remains under File 17 retention rules.', 'sabri-network')],
            'done' => true,
        ];
    }

    private static function issuance_receipt(object $row): array {
        return [
            'contract' => self::CONTRACT_NAME,
            'contract_version' => self::CONTRACT_VERSION,
            'producer_version' => defined('SN_VERSION') ? SN_VERSION : '',
            'reference' => [
                'reference_uuid' => (string) $row->reference_uuid,
                'reference_version' => (int) $row->version,
                'purpose' => (string) $row->purpose,
                'status' => (string) $row->status,
                'reference_expires_at' => self::iso_time((string) $row->expires_at),
                'retention_class' => (string) $row->retention_class,
                'consent_verified' => true,
            ],
            'destination_intent' => self::destination_intent((string) $row->reference_uuid),
            'content_boundary' => self::content_boundary(),
            'authorization_boundary' => self::authorization_boundary(),
            'result' => 'valid',
            'reason_code' => 'communication_context_reference_issued',
        ];
    }

    private static function destination_intent(string $reference_uuid): array {
        return [
            'route_key' => 'messages-conversation',
            'reference_uuid' => $reference_uuid,
            'requires_click_time_authentication' => true,
            'requires_click_time_file17_authorization' => true,
            'contains_bearer_authorization' => false,
        ];
    }

    private static function content_boundary(): array {
        return [
            'message_body_included' => false,
            'attachment_included' => false,
            'call_payload_included' => false,
            'call_transcript_included' => false,
            'message_search_result_included' => false,
            'participant_contact_included' => false,
            'automatic_chart_write' => false,
            'clinician_authored_summary_required_separately' => true,
        ];
    }

    private static function authorization_boundary(): array {
        return [
            'treating_relationship' => false,
            'clinical_read_authority' => false,
            'clinical_write_authority' => false,
            'prescription_authority' => false,
            'break_glass_authority' => false,
            'chat_membership_is_not_treating_relationship' => true,
            'requires_cf01_action_time_authorization' => true,
        ];
    }

    private static function conversation(int $conversation_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,type,owner_id,privacy,status,updated_at FROM ' . SN_DB::table('conversations') . ' WHERE id=%d',
            $conversation_id
        ));
        return is_object($row) ? $row : null;
    }

    private static function participant_count(int $conversation_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND left_at IS NULL',
            $conversation_id
        ));
    }

    private static function participant_class(int $conversation_id, int $actor_id): string {
        $role = sanitize_key(SN_DB::member_role($conversation_id, $actor_id));
        return in_array($role, ['owner', 'administrator', 'moderator', 'editor', 'member', 'observer'], true) ? $role : 'member';
    }

    private static function direct_conversation_blocked(object $conversation, int $actor_id): bool {
        global $wpdb;
        if ((string) $conversation->type !== 'direct') {
            return false;
        }
        $other = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY id ASC LIMIT 1',
            (int) $conversation->id,
            $actor_id
        ));
        return $other <= 0 || SN_DB::is_blocked($actor_id, $other);
    }

    private static function conversation_state_hash(object $conversation, int $participant_count): string {
        return hash_hmac('sha256', implode('|', [
            (int) $conversation->id,
            sanitize_key((string) $conversation->type),
            (int) $conversation->owner_id,
            sanitize_key((string) $conversation->privacy),
            sanitize_key((string) $conversation->status),
            (string) $conversation->updated_at,
            $participant_count,
        ]), wp_salt('auth'));
    }

    private static function subject_reference(int $user_id): string {
        return $user_id > 0 ? 'sn-subject-' . substr(self::keyed_hash((string) $user_id, 'subject'), 0, 32) : '';
    }

    private static function retention_class(string $value): string {
        $value = sanitize_key($value);
        return in_array($value, self::RETENTION_CLASSES, true) ? $value : 'communication_standard';
    }

    private static function opaque_value(string $value): string {
        $value = trim(wp_unslash($value));
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,190}$/', $value) ? $value : '';
    }

    private static function keyed_hash(string $value, string $purpose): string {
        return hash_hmac('sha256', $purpose . '|' . $value, wp_salt('nonce'));
    }

    private static function valid_uuid(string $value): bool {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    private static function same_origin_https(string $url): bool {
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        return is_array($parts)
            && is_array($home)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strcasecmp((string) ($parts['host'] ?? ''), (string) ($home['host'] ?? '')) === 0
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private static function timestamp(string $value): int {
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? 0 : $timestamp;
    }

    private static function iso_time(string $value): string {
        $timestamp = self::timestamp($value);
        return $timestamp > 0 ? gmdate('c', $timestamp) : '';
    }

    private static function table(): string {
        return SN_DB::table('cf01_context_refs');
    }

    private static function now(): string {
        return current_time('mysql', true);
    }

    private static function not_found(): WP_Error {
        return self::error('sn_cf01_reference_not_found', 'The communication-context reference is unavailable.', 404);
    }

    private static function error(string $code, string $message, int $status): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}

function sn_cf01_issue_communication_context(int $conversation_id, int $actor_id, array $context): array|WP_Error {
    return SN_CF01_Clinical_Context::issue_reference($conversation_id, $actor_id, $context);
}
function sn_cf01_communication_context_assertion(string $reference_uuid, int $actor_id, array $context = []): array|WP_Error {
    return SN_CF01_Clinical_Context::assertion($reference_uuid, $actor_id, $context);
}
function sn_cf01_resolve_communication_destination(string $reference_uuid, int $actor_id, array $context = []): array|WP_Error {
    return SN_CF01_Clinical_Context::resolve_destination($reference_uuid, $actor_id, $context);
}
function sn_cf01_revoke_communication_context(string $reference_uuid, int $actor_id, string $reason = ''): array|WP_Error {
    return SN_CF01_Clinical_Context::revoke_reference($reference_uuid, $actor_id, $reason);
}
function sn_cf01_communication_context_contract(): array {
    return SN_CF01_Clinical_Context::contract();
}
