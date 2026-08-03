<?php
/** Canonical communities, groups, channels and private-team governance. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Spaces {
    private const SCHEMA_VERSION = '2.0.0';
    private const TYPES = ['community','group','channel','private_team'];
    private const VISIBILITIES = ['public','discoverable_private','invite_only','hidden'];
    private const STATES = ['active','restricted','locked','archived','closed','deletion_requested'];
    private const ROLES = ['owner','administrator','moderator','editor','member','observer'];
    private const JOIN_POLICIES = ['open','request','invite'];
    private const POSTING_POLICIES = ['members','approved','roles','disabled'];
    private const ROLE_RANK = ['observer'=>10,'member'=>20,'editor'=>30,'moderator'=>40,'administrator'=>50,'owner'=>60];
    private const MAX_LIST = 100;

    use SN_Spaces_Schema;
    use SN_Spaces_Membership;
    use SN_Spaces_Governance;
    use SN_Spaces_Lifecycle;
}
