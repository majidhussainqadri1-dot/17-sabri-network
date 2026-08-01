<?php
/** Safety/privacy static contracts; WordPress is intentionally not loaded. */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

function safety_check(bool $condition, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function safety_content(string $relative): string {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: $relative");
    }
    return (string) file_get_contents($path);
}

$main = safety_content('sabri-network.php');
$db = safety_content('includes/class-sn-db.php');
$safety = safety_content('includes/class-sn-safety.php');
$rest = safety_content('includes/class-sn-rest.php');
$privacy = safety_content('includes/class-sn-privacy.php');
$admin = safety_content('includes/class-sn-admin.php');
$js = safety_content('assets/js/network.js');

safety_check(str_contains($main, "includes/class-sn-safety.php"), 'The safety/retention service must be loaded by the plugin bootstrap.');
safety_check(str_contains($db, "DB_VERSION = '2.0.2'"), 'The database schema version must advance for report retention fields.');
safety_check(str_contains($db, 'client_uuid CHAR(36)') && str_contains($db, 'UNIQUE KEY reporter_client'), 'Report idempotency must be database enforced.');
safety_check(str_contains($db, 'target_key CHAR(64)') && str_contains($db, 'KEY target_created'), 'Reports must carry a canonical target key and target-time index.');
safety_check(str_contains($db, 'legal_hold TINYINT(1)') && str_contains($db, 'retention_until DATETIME'), 'Legal-hold and retention fields must exist.');
safety_check(str_contains($db, 'anonymized_at DATETIME') && str_contains($db, 'version INT UNSIGNED'), 'Report minimization and optimistic version fields must exist.');
safety_check(str_contains($db, 'SN_Safety::migrate_reports()'), 'Report migration must run during schema installation.');
safety_check(str_contains($db, 'SN_Safety::purge_expired_reports()'), 'Hourly cleanup must invoke report retention minimization.');
safety_check(str_contains($safety, 'valid_uuid') && str_contains($safety, "-4[0-9a-f]{3}-[89ab]"), 'Report client identifiers must be UUIDv4 values.');
safety_check(str_contains($safety, 'report_retention_days') && str_contains($safety, 'HIGH_RISK_RETENTION_DAYS'), 'Retention must be category-aware and bounded through an integration filter.');
safety_check(str_contains($safety, 'report_retention_anonymized') && str_contains($safety, "status='expired'"), 'Expired report minimization must be audited and marked.');
safety_check(str_contains($rest, "'/admin/reports'") && str_contains($rest, 'admin_reports'), 'Administrator report inventory must exist.');
safety_check(str_contains($rest, "'/admin/reports/(?P<id>\\d+)'"), 'Administrator report triage route must exist.');
safety_check(str_contains($rest, 'report_idempotency_conflict') && str_contains($rest, "'duplicate' => true"), 'Report retries and UUID conflicts must be distinguished.');
safety_check(str_contains($rest, "consume_rate_limit('report_global'") && str_contains($rest, "consume_rate_limit('report_target'"), 'Global and same-target report abuse limits must both be applied.');
safety_check(str_contains($rest, '\'client_uuid\' => $client_uuid') && str_contains($rest, "'evidence_hash' => SN_Safety::evidence_hash"), 'Report writes must persist idempotency and evidence-integrity fields.');
safety_check(str_contains($rest, 'report_update_conflict') && str_contains($rest, 'version=version+1'), 'Triage updates must use optimistic concurrency.');
safety_check(str_contains($privacy, 'SN_Safety::erase_user_report_data') && str_contains($privacy, 'items_retained'), 'Privacy erasure must delegate report-specific hold-aware minimization.');
safety_check(str_contains($admin, 'Retention actions due') && str_contains($admin, 'Legal/safety holds'), 'Administrator diagnostics must surface report retention and holds.');
safety_check(str_contains($js, 'client_id:clientId') && str_contains($js, 'window.crypto?.getRandomValues'), 'The client must retain a secure UUID across report retries.');

if ($failures) {
    fwrite(STDERR, "Safety static contract failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}

echo "Safety static contracts: PASS ($checks checks)\n";
