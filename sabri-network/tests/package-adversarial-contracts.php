<?php
/** Fresh/adversarial deterministic-package contracts; WordPress is intentionally not loaded. */

declare(strict_types=1);

$root = dirname(__DIR__);
$package = (string) file_get_contents($root . '/tools/package.sh');
$quality = (string) file_get_contents($root . '/tools/quality-check.sh');
$failures = [];
$checks = 0;

function package_adv_check(bool $condition, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

package_adv_check(str_contains($package, 'find "$STAGE/sabri-network" -type l') && str_contains($package, 'Packaging refused: symbolic links'), 'Release packaging must reject symbolic links.');
package_adv_check(str_contains($package, "--exclude='build/'") && str_contains($package, "--exclude='tests/'") && str_contains($package, "--exclude='tools/'"), 'Development and recursive build files must be excluded.');
package_adv_check(!preg_match('/\bdate\s+\+|current_time|time\(\)/', $package), 'Release bytes must not depend on the current clock.');
package_adv_check(str_contains($package, 'touch -h -t "$FIXED_TIMESTAMP"'), 'Every staged entry must receive the fixed timestamp.');
package_adv_check(str_contains($package, 'sort | zip -X'), 'ZIP ordering must be sorted and platform extras stripped.');
package_adv_check(str_contains($package, "! -name 'MANIFEST.sha256'") && str_contains($package, '> sabri-network/MANIFEST.sha256'), 'The embedded manifest must not recursively hash itself.');
package_adv_check(str_contains($quality, 'first_hash=') && str_contains($quality, 'second_hash='), 'Independent builds must each be hashed.');
package_adv_check(str_contains($quality, 'if [[ "$first_hash" != "$second_hash" ]]'), 'Hash disagreement must fail the quality gate.');
package_adv_check(str_contains($quality, 'cmp -s /tmp/file17-package-first.zip'), 'A hash-only false positive must be prevented by byte comparison.');
package_adv_check(str_contains($quality, 'cmp -s "build/${BASE}.manifest.sha256" "$VERIFY/sabri-network/MANIFEST.sha256"'), 'Detached and embedded source manifests must be byte-identical.');
package_adv_check(str_contains($quality, 'sha256sum -c "${BASE}.zip.sha256"'), 'The detached package checksum must be verified.');
package_adv_check(str_contains($quality, 'sha256sum -c sabri-network/MANIFEST.sha256'), 'Extracted package contents must be verified against the embedded manifest.');

if ($failures) {
    fwrite(STDERR, "Package adversarial contract failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}

echo "Package adversarial contracts: PASS ($checks checks)\n";
