<?php
/** Fresh-20 Round 20 final repository/release-truth contracts. WordPress is intentionally not loaded. */
declare(strict_types=1);

$root = dirname(__DIR__);
$repo = dirname($root);
$fail = [];
$checks = 0;
$check = static function (bool $ok, string $message) use (&$fail, &$checks): void {
    $checks++;
    if (!$ok) $fail[] = $message;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$readRepo = static fn(string $path): string => (string) file_get_contents($repo . '/' . $path);

$quality = $read('tools/quality-check.sh');
$package = $read('tools/package.sh');
$loader = $read('includes/class-sn-future24-review-hardening.php');
$workflow = (string) file_get_contents($repo . '/.github/workflows/quality.yml');
$rootReadme = $readRepo('README.md');
$status = $readRepo('STATUS.md');
$coding = $readRepo('CODING-COMPLETENESS.md');
$pluginReadme = $read('README.md');
$pluginWpReadme = $read('readme.txt');
$systemStatus = $read('SYSTEM-STATUS.txt');
$qaInventory = $read('QA-INVENTORY.txt');
$boundary = $read('CURRENT-CANDIDATE-BOUNDARY.txt');
$changelog = $read('CHANGELOG.md');

$branch = 'review/file17-fresh-20-round-2026-09-06';
$defects = 'R1, R2, R3, R5, R7, R8, R11, R12, R15, R16, R17, R18, R19, R20';
$clean = 'R4, R6, R9, R10, R13, R14';

// One warning-fatal executable test path in both workflow jobs.
$wrapperCall = 'php "sabri-network/tools/run-php-test.php" "sabri-network/tests/$file"';
$check(str_contains($workflow, $wrapperCall), 'R20: PHP 8.1 current-boundary tests must use the canonical warning-fatal wrapper.');
foreach (['r17-canonical-auth-dual-approval-contracts.php', 'r18-quality-warning-contracts.php', 'r20-final-cycle-contracts.php'] as $suite) {
    $check(str_contains($workflow, 'run_test ' . $suite), 'R20: PHP 8.1 current-boundary job must execute ' . $suite . '.');
}
$check(!str_contains($workflow, 'php sabri-network/tests/'), 'R20: PHP 8.3 must not re-run a weaker direct-PHP regression subset after the canonical full gate.');
$check(str_contains($workflow, 'bash sabri-network/tools/quality-check.sh'), 'R20: PHP 8.3 release job must retain the canonical full quality gate.');

// Active loader, canonical source gate and package gate must name the same late runtime owners.
$late = [
    'includes/class-sn-next-message-operations-hardening.php',
    'includes/class-sn-r6-transaction-hardening.php',
    'includes/class-sn-r7-privacy-hardening.php',
    'includes/class-sn-r8-interop-finalization-hardening.php',
    'includes/class-sn-r9-runtime-hardening.php',
    'includes/class-sn-fresh20-r7-message-read-hardening.php',
];
foreach ($late as $surface) {
    $classFile = basename($surface);
    $check(str_contains($loader, $classFile), 'R20: active loader is missing ' . $classFile . '.');
    $check(str_contains($quality, $surface), 'R20: canonical source required-surface gate is missing ' . $surface . '.');
    $check(str_contains($package, $surface), 'R20: deterministic package required-surface gate is missing ' . $surface . '.');
}

$check(!file_exists($repo . '/.github/workflows/r2-yet-fix.yml'), 'R20: obsolete historical write-capable patch workflow must be retired.');

// Executable inventory is authoritative and documentation must match this exact tree.
$tests = glob($root . '/tests/*.php');
$testCount = is_array($tests) ? count($tests) : 0;
$check($testCount >= 64, 'R20: final PHP regression inventory unexpectedly contracted.');
foreach ([
    'root README' => $rootReadme,
    'STATUS' => $status,
    'CODING-COMPLETENESS' => $coding,
    'plugin README' => $pluginReadme,
    'plugin readme.txt' => $pluginWpReadme,
    'SYSTEM-STATUS' => $systemStatus,
    'QA-INVENTORY' => $qaInventory,
    'CURRENT-CANDIDATE-BOUNDARY' => $boundary,
    'CHANGELOG' => $changelog,
] as $name => $text) {
    $check(str_contains($text, $branch), 'R20: ' . $name . ' must identify the active fresh-20 review branch.');
    $check(str_contains($text, (string) $testCount) && str_contains($text, '10'), 'R20: ' . $name . ' must match the exact current PHP/JS executable inventory.');
    $check(str_contains($text, $defects), 'R20: ' . $name . ' must preserve the final fresh-20 defect-bearing round record.');
    $check(str_contains($text, $clean), 'R20: ' . $name . ' must preserve the final fresh-20 clean-round record.');
}

// Historical another-fresh/60-suite evidence may remain, but only under explicit historical labeling.
foreach (['root README' => $rootReadme, 'STATUS' => $status, 'CODING-COMPLETENESS' => $coding, 'plugin readme.txt' => $pluginWpReadme, 'CHANGELOG' => $changelog, 'QA-INVENTORY' => $qaInventory, 'CURRENT-CANDIDATE-BOUNDARY' => $boundary] as $name => $text) {
    if (str_contains($text, 'review/file17-another-10-round-2026-09-05') || str_contains($text, '60 PHP')) {
        $check(str_contains(strtolower($text), 'historical'), 'R20: ' . $name . ' may retain prior branch/60-suite facts only as historical attribution.');
    }
}

foreach ([$rootReadme, $status, $coding, $pluginReadme, $pluginWpReadme, $systemStatus, $boundary, $changelog] as $text) {
    $lower = strtolower($text);
    $check(!str_contains($lower, 'live-deployed: true') && !str_contains($lower, 'operational: true') && !str_contains($lower, 'production-ready: true'), 'R20: repository documentation must not promote source/CI evidence into live or operational truth.');
}

if ($fail) {
    fwrite(STDERR, "R20 final-cycle contract failures (" . count($fail) . "/$checks):\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "R20 final-cycle contracts: PASS ($checks checks; $testCount PHP suites)\n";
