<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Canonical File-17 event-schema registry.
 *
 * Logical event type names remain stable for compatibility; the immutable contract
 * name/version travels beside them so consumers can validate/deprecate schemas
 * without treating an unversioned logical routing key as the schema identity.
 */
final class SN_Event_Schema_Registry {
    private const OWNER = 'file-17';
    private const DELIVERED_RETENTION = 'outbox-delivered-30d';
    private const DEAD_RETENTION = 'outbox-dead-180d';

    public static function all(): array {
        $message_consumers = ['file-19', 'file-24', 'registered-projections'];
        $operational_consumers = ['file-24', 'registered-projections'];
        $space_consumers = ['file-19', 'file-24', 'registered-projections'];

        $registry = [
            'message.sent' => self::spec('MessageSent.v1', ['message_id','conversation_id','sender_id'], ['message_type','created_at'], 'highly-sensitive', $message_consumers),
            'message.edited' => self::spec('MessageEdited.v1', ['message_id','conversation_id'], ['sender_id','edited_at','version'], 'highly-sensitive', $message_consumers),
            'message.deleted' => self::spec('MessageDeleted.v1', ['message_id','conversation_id'], ['sender_id','deleted_by','deleted_at','version'], 'highly-sensitive', $message_consumers),
            'message.delivered' => self::spec('MessageDelivered.v1', ['conversation_id','recipient_id','through_message_id','state'], ['requested_message_id'], 'member-private', $message_consumers),
            'message.read' => self::spec('MessageRead.v1', ['conversation_id','recipient_id','through_message_id','state'], ['requested_message_id'], 'member-private', $message_consumers),
            'message.expired' => self::spec('MessageExpired.v1', ['message_id','conversation_id','expired_at'], ['version'], 'highly-sensitive', $message_consumers),
            'message.expiry_changed' => self::spec('MessageExpiryChanged.v1', ['message_id','conversation_id'], ['expires_at','version'], 'highly-sensitive', $message_consumers),
            'message.forwarded' => self::spec('MessageForwarded.v1', ['message_id','conversation_id','sender_id'], ['source_scope_hash','source_visible'], 'highly-sensitive', $message_consumers),
            'message.mentions_updated' => self::spec('MessageMentionsUpdated.v1', ['message_id','conversation_id'], ['mention_count','mentioned_user_ids'], 'member-private', $message_consumers),

            'message_request.created' => self::spec('MessageRequestCreated.v1', ['request_id','requester_id','recipient_id'], ['context_type','context_id','expires_at'], 'highly-sensitive', $message_consumers),
            'message_request.accepted' => self::spec('MessageRequestAccepted.v1', ['request_id','conversation_id','message_id'], [], 'highly-sensitive', $message_consumers),
            'message_request.declined' => self::spec('MessageRequestDeclined.v1', ['request_id','requester_id','recipient_id'], ['report_id'], 'highly-sensitive', $message_consumers),
            'message_request.reported' => self::spec('MessageRequestReported.v1', ['request_id','requester_id','recipient_id'], ['report_id'], 'highly-sensitive', $message_consumers),
            'message_request.cancelled' => self::spec('MessageRequestCancelled.v1', ['request_id','requester_id','recipient_id'], ['report_id'], 'highly-sensitive', $message_consumers),

            'checklist.item_changed' => self::spec('ChecklistItemChanged.v1', ['message_id','item','done','actor_id'], [], 'member-private', $message_consumers),
            'community_artifact.created' => self::spec('CommunityArtifactCreated.v1', ['artifact_id','space_id','type','author_id'], [], 'member-private', $space_consumers),
            'smail.sent' => self::spec('SmailSent.v1', ['smail_id','conversation_id','message_id','sender_id'], ['recipient_count'], 'highly-sensitive', $message_consumers),

            'space.created' => self::spec('SpaceCreated.v1', ['space_id','conversation_id','type','owner_id'], ['visibility','created_at'], 'member-private', $space_consumers),
            'space.join_request_accepted' => self::spec('SpaceJoinRequestAccepted.v1', ['space_id','user_id','decision'], [], 'member-private', $space_consumers),
            'space.join_request_rejected' => self::spec('SpaceJoinRequestRejected.v1', ['space_id','user_id','decision'], [], 'member-private', $space_consumers),
            'space.invitation_created' => self::spec('SpaceInvitationCreated.v1', ['space_id','invite_id','invitee_id'], ['expires_at'], 'member-private', $space_consumers),
            'space.invitation_accepted' => self::spec('SpaceInvitationAccepted.v1', ['space_id','invite_id','user_id','status'], [], 'member-private', $space_consumers),
            'space.invitation_rejected' => self::spec('SpaceInvitationRejected.v1', ['space_id','invite_id','user_id','status'], [], 'member-private', $space_consumers),
            'space.invitation_cancelled' => self::spec('SpaceInvitationCancelled.v1', ['space_id','invite_id','user_id','status'], [], 'member-private', $space_consumers),
            'space.member_joined' => self::spec('SpaceMemberJoined.v1', ['space_id','user_id'], ['role'], 'member-private', $space_consumers),
            'space.member_left' => self::spec('SpaceMemberLeft.v1', ['space_id','user_id'], [], 'member-private', $space_consumers),
            'space.member_removed' => self::spec('SpaceMemberRemoved.v1', ['space_id','user_id'], [], 'member-private', $space_consumers),
            'space.member_role_changed' => self::spec('SpaceMemberRoleChanged.v1', ['space_id','user_id','role'], [], 'member-private', $space_consumers),
            'space.member_banned' => self::spec('SpaceMemberBanned.v1', ['space_id','user_id'], ['expires_at'], 'sensitive', $space_consumers),
            'space.lifecycle_changed' => self::spec('SpaceLifecycleChanged.v1', ['space_id','state','version'], [], 'member-private', $space_consumers),
            'space.ownership_transferred' => self::spec('SpaceOwnershipTransferred.v1', ['space_id','former_owner_id','new_owner_id'], [], 'member-private', $space_consumers),

            'conversation.ownership_transferred' => self::spec('ConversationOwnershipTransferred.v1', ['conversation_id','former_owner_id','new_owner_id'], [], 'member-private', $message_consumers),
            'conversation.context_attached' => self::spec('ConversationContextAttached.v1', ['conversation_id','context_uuid','provider'], ['provider_object_hash','expires_at'], 'sensitive', $message_consumers),
            'conversation.context_detached' => self::spec('ConversationContextDetached.v1', ['conversation_id','context_uuid','provider'], [], 'sensitive', $message_consumers),
            'conversation.clinical_context_reference_issued' => self::spec('ConversationClinicalContextReferenceIssued.v1', ['reference_uuid','purpose','expires_at'], ['conversation_state_hash','contains_message_body','contains_attachment','contains_call_transcript'], 'highly-sensitive', ['file-24','registered-clinical-context-consumers']),

            'file-transfer.initiated' => self::spec('FileTransferInitiated.v1', ['transfer_id','sender_id','recipient_count','total_bytes'], [], 'highly-sensitive', $message_consumers),
            'file-transfer.ready' => self::spec('FileTransferReady.v1', ['transfer_id','sender_id','bytes','sha256'], [], 'highly-sensitive', $message_consumers),
            'file-transfer.revoked' => self::spec('FileTransferRevoked.v1', ['transfer_id','sender_id'], [], 'highly-sensitive', $message_consumers),

            'conference.provider_configured' => self::spec('ConferenceProviderConfigured.v1', ['provider_key','provider_type','status'], ['endpoint_origin_hash','configuration_version'], 'sensitive', $operational_consumers),
        ];

        $filtered = apply_filters('sn_network_event_schema_registry', $registry);
        return is_array($filtered) ? $filtered : $registry;
    }

    public static function schema_for(string $event_type): array|WP_Error {
        $event_type = strtolower(trim($event_type));
        $registry = self::all();
        if (!isset($registry[$event_type]) || !is_array($registry[$event_type])) {
            return new WP_Error('event_schema_unregistered', 'This File-17 event type has no approved schema contract.');
        }
        $schema = $registry[$event_type];
        $required_keys = ['contract','owner','version','required_fields','optional_fields','privacy_class','retention','consumers','deprecation_date'];
        foreach ($required_keys as $key) {
            if (!array_key_exists($key, $schema)) {
                return new WP_Error('event_schema_invalid', 'The File-17 event schema contract is incomplete.');
            }
        }
        if (!is_string($schema['contract']) || !preg_match('/^[A-Za-z][A-Za-z0-9]+\.v[1-9][0-9]*$/', $schema['contract'])) {
            return new WP_Error('event_schema_invalid', 'The File-17 event contract identity is invalid.');
        }
        if ((string)$schema['owner'] !== self::OWNER || !preg_match('/^[1-9][0-9]*$/', (string)$schema['version'])) {
            return new WP_Error('event_schema_invalid', 'The File-17 event owner or version is invalid.');
        }
        if (!is_array($schema['required_fields']) || !is_array($schema['optional_fields']) || !is_array($schema['consumers'])) {
            return new WP_Error('event_schema_invalid', 'The File-17 event field or consumer registry is invalid.');
        }
        return $schema;
    }

    public static function validate_payload(string $event_type, array $payload): array|WP_Error {
        $schema = self::schema_for($event_type);
        if (is_wp_error($schema)) return $schema;
        foreach ($schema['required_fields'] as $field) {
            $field = sanitize_key((string)$field);
            if ($field === '' || !array_key_exists($field, $payload)) {
                return new WP_Error('event_schema_payload_missing', 'The File-17 event metadata is missing a required schema field.');
            }
        }
        return $schema;
    }

    private static function spec(string $contract, array $required, array $optional, string $privacy, array $consumers): array {
        return [
            'contract' => $contract,
            'owner' => self::OWNER,
            'version' => '1',
            'required_fields' => array_values($required),
            'optional_fields' => array_values($optional),
            'privacy_class' => $privacy,
            'retention' => [
                'delivered' => self::DELIVERED_RETENTION,
                'dead_letter' => self::DEAD_RETENTION,
            ],
            'consumers' => array_values($consumers),
            'deprecation_date' => null,
        ];
    }
}
