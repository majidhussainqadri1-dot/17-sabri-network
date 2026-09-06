<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(2);
}

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "A valid PHP regression script is required.\n");
    exit(2);
}

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require $path;
} catch (Throwable $e) {
    fwrite(STDERR, "PHP regression runtime failure: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");
    exit(1);
}
