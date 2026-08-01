<?php
/** Deterministic package static contracts; WordPress is intentionally not loaded. */

declare(strict_types=1);

$root = dirname(__DIR__);
$package = (string) file_get_contents($root . '/tools/package.sh');
$quality = (string) file_get_contents($root . '/tools/quality-check.sh');
$failures = [];
$checks = 0;

function package_check(bool $condition, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

package_check(str_contains($package, 'export LC_ALL=C'), 'Packaging locale must be fixed.');
package_check(str_contains($package, 'export TZ=UTC'), 'Packaging timezone must be fixed.');
package_check(str_contains($package, 'FIXED_TIMESTAMP="198001010000.00"'), 'Release entries must use a fixed ZIP-compatible timestamp.');
package_check(str_contains($package, 'find "$STAGE/sabri-network" -type f -exec chmod 0644'), 'Release file modes must be normalized.');
package_check(str_contains($package, 'find "$STAGE/sabri-network" -type d -exec chmod 0755'), 'Release directory modes must be normalized.');
package_check(str_contains($package, 'find sabri-network -type f -print | sort | zip -X -q'), 'ZIP input ordering and extra metadata must be deterministic.');
package_check(substr_count($quality, 'bash tools/package.sh') >= 2, 'The quality gate must build the package twice.');
package_check(str_contains($quality, 'cmp -s /tmp/file17-package-first.zip'), 'The quality gate must compare release bytes, not only filenames.');

if ($failures) {
    fwrite(STDERR, "Package static contract failures (" . count($failures) . "/$checks):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}

echo "Package static contracts: PASS ($checks checks)\n";
