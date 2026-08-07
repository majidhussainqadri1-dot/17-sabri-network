<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/sabri-network.php');
$source = file_get_contents($root . '/includes/class-sn-cf01-clinical-context.php');
$readme = file_get_contents($root . '/readme.txt');

$tests = 0;
function sn_cf01_static_assert(bool $condition, string $message): void {
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

sn_cf01_static_assert(str_contains($main, 'Version: 2.1.0'), 'plugin header is File 17 2.1.0');
sn_cf01_static_assert(str_contains($main, "define('SN_VERSION', '2.1.0')"), 'runtime version is File 17 2.1.0');
sn_cf01_static_assert(str_contains($main, "define('SN_CF01_COMMUNICATION_CONTEXT_VERSION', '1.0.0')"), 'CF-01 contract version is explicit');
sn_cf01_static_assert(str_contains($main, 'class-sn-cf01-clinical-context.php'), 'provider loads from bootstrap');
sn_cf01_static_assert(str_contains($main, 'SN_CF01_Clinical_Context::register()'), 'provider lifecycle is registered');
sn_cf01_static_assert(str_contains($main, 'SN_CF01_Clinical_Context::install()'), 'provider schema is installed');
sn_cf01_static_assert(str_contains($source, "public const CONTRACT_NAME = 'sn.cf01.communication-context'"), 'contract name is exact');
sn_cf01_static_assert(str_contains($source, "public const CONTRACT_VERSION = '1.0.0'"), 'contract version is exact');

preg_match_all("/SN_DB::table\('([^']+)'\)/", $source, $table_matches);
$used_tables = array_values(array_unique($table_matches[1] ?? []));
sort($used_tables);
$allowed_tables = ['cf01_context_refs', 'conversations', 'members'];
sort($allowed_tables);
sn_cf01_static_assert($used_tables === $allowed_tables, 'provider uses only conversations, members and its minimal reference registry');

sn_cf01_static_assert(!str_contains($source, 'SELECT body'), 'provider never selects message bodies');
sn_cf01_static_assert(!str_contains($source, 'attachment_id BIGINT'), 'reference schema stores no attachment identifier');
sn_cf01_static_assert(!str_contains($source, 'message_id BIGINT'), 'reference schema stores no message identifier');
sn_cf01_static_assert(!str_contains($source, 'call_id BIGINT'), 'reference schema stores no call identifier');
sn_cf01_static_assert(str_contains($source, "'message_body_included' => false"), 'message-body exclusion is explicit');
sn_cf01_static_assert(str_contains($source, "'attachment_included' => false"), 'attachment exclusion is explicit');
sn_cf01_static_assert(str_contains($source, "'call_transcript_included' => false"), 'call-transcript exclusion is explicit');
sn_cf01_static_assert(str_contains($source, "'automatic_chart_write' => false"), 'automatic chart writes are prohibited');
sn_cf01_static_assert(str_contains($source, "'chat_membership_is_not_treating_relationship' => true"), 'chat membership never creates a treating relationship');
sn_cf01_static_assert(str_contains($source, "'clinical_write_authority' => false"), 'communication reference never grants clinical write authority');
sn_cf01_static_assert(str_contains($source, "'prescription_authority' => false"), 'communication reference never grants prescription authority');
sn_cf01_static_assert(str_contains($source, "'break_glass_authority' => false"), 'communication reference never grants break-glass authority');
sn_cf01_static_assert(str_contains($source, "'sn_cf01_clinical_context_issuer_authorized'"), 'issuer authority is externally and fail-closed validated');
sn_cf01_static_assert(str_contains($source, "'sn_cf01_clinical_context_consent_authorized'"), 'consent authority is externally and fail-closed validated');
sn_cf01_static_assert(str_contains($source, "'sn_cf01_clinical_context_read_authorized'"), 'every read requires fresh external authorization');
sn_cf01_static_assert(str_contains($source, "'sn_cf01_clinical_context_retention_class'"), 'retention class comes from a File 17/native-owner filter');
sn_cf01_static_assert(str_contains($source, "'requires_click_time_file17_authorization' => true"), 'destination resolution requires click-time File 17 authorization');
sn_cf01_static_assert(str_contains($source, "'contains_bearer_authorization' => false"), 'destination intent is not bearer authorization');
sn_cf01_static_assert(str_contains($source, 'idempotency_key CHAR(64) NOT NULL'), 'reference issuance is idempotent');
sn_cf01_static_assert(str_contains($source, 'version BIGINT UNSIGNED NOT NULL DEFAULT 1'), 'reference has optimistic version evidence');
sn_cf01_static_assert(str_contains($source, "'state_version' => self::conversation_state_hash"), 'conversation state has a bounded state version');
sn_cf01_static_assert(str_contains($source, "'participant_contact_included' => false"), 'participant contact data is excluded');
sn_cf01_static_assert(str_contains($source, "'consent_verified' => true"), 'verified consent status is explicit without exposing consent content');
sn_cf01_static_assert(str_contains($source, "&& !isset(\$parts['user'])"), 'same-origin validation rejects URL user info');
sn_cf01_static_assert(str_contains($source, "&& !isset(\$parts['pass'])"), 'same-origin validation rejects URL password info');
sn_cf01_static_assert(str_contains($source, 'register_exporter'), 'reference privacy export is implemented');
sn_cf01_static_assert(str_contains($source, 'register_eraser'), 'reference privacy erasure/revocation is implemented');
sn_cf01_static_assert(str_contains($source, 'sn_cf01_communication_context_assertion'), 'owner-executed assertion function exists');
sn_cf01_static_assert(str_contains($source, 'sn_cf01_resolve_communication_destination'), 'owner-executed destination resolver exists');
sn_cf01_static_assert(str_contains($readme, 'Stable tag: 2.1.0'), 'readme stable tag is File 17 2.1.0');

echo "File 17 CF-01 static contracts: $tests PASS, 0 FAIL\n";
