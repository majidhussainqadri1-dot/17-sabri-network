<?php
defined('ABSPATH') || exit;

/** Internal Gmail-like communication centre, backed by File-17 canonical conversations/messages. */
require_once __DIR__ . '/class-sn-smail-part-1.php';
require_once __DIR__ . '/class-sn-smail-part-2.php';
require_once __DIR__ . '/class-sn-smail-part-3.php';
require_once __DIR__ . '/class-sn-smail-part-4.php';

final class SN_Smail {

    private const SCHEMA_VERSION = '1.0.0';
    private const PAGE_OWNER_META = '_sn_smail_owned';
    private const MAX_RECIPIENTS = 50;
    private const MAX_SUBJECT = 200;
    private const MAX_DRAFTS = 500;
    use SN_Smail_Part_1, SN_Smail_Part_2, SN_Smail_Part_3, SN_Smail_Part_4;
}
