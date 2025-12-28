<?php
/**
 * Banggood Import - cleanup module log files (OpenCart 3.x)
 *
 * Deletes ONLY the Banggood import debug logs and the optional cron log file.
 * Safe patterns:
 * - banggood_import_debug_*.log
 * - banggood_cron.log
 *
 * Usage:
 *   php cron/banggood_import_cleanup_logs.php
 *   php cron/banggood_import_cleanup_logs.php --admin-dir=admin
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI only.\n";
    exit(1);
}

function bg_arg(array $argv, string $name, $default = null) {
    $prefix = '--' . $name . '=';
    foreach ($argv as $a) {
        if (strpos($a, $prefix) === 0) {
            return substr($a, strlen($prefix));
        }
    }
    return $default;
}

$adminDir = (string)bg_arg($argv, 'admin-dir', 'admin');
$adminDir = trim($adminDir, "/ \t\n\r\0\x0B");
if ($adminDir === '') $adminDir = 'admin';

$root = realpath(__DIR__ . '/..');
if (!$root) {
    fwrite(STDERR, "Unable to resolve store root.\n");
    exit(1);
}

$adminConfig = $root . '/' . $adminDir . '/config.php';
if (!is_file($adminConfig)) {
    fwrite(STDERR, "Missing admin config at: {$adminConfig}\n");
    fwrite(STDERR, "If your admin folder is renamed, pass --admin-dir=YOUR_ADMIN_FOLDER\n");
    exit(1);
}

require_once $adminConfig;

$paths = [];
if (defined('DIR_STORAGE') && DIR_STORAGE) $paths[] = rtrim((string)DIR_STORAGE, '/\\');
if (defined('DIR_SYSTEM') && DIR_SYSTEM) {
    $paths[] = rtrim((string)DIR_SYSTEM, '/\\') . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'storage';
    $paths[] = rtrim((string)DIR_SYSTEM, '/\\') . DIRECTORY_SEPARATOR . 'storage';
}
if (defined('DIR_APPLICATION') && DIR_APPLICATION) {
    $paths[] = rtrim((string)DIR_APPLICATION, '/\\') . DIRECTORY_SEPARATOR . 'storage';
}
if (defined('DIR_LOGS') && DIR_LOGS) $paths[] = rtrim((string)DIR_LOGS, '/\\');

$paths = array_values(array_unique(array_filter($paths, function ($p) { return is_string($p) && $p !== ''; })));

$deleted = 0;
$missing = 0;
$skipped = 0;

foreach ($paths as $dir) {
    $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
    if (!is_dir($dir)) continue;

    // Delete banggood_import_debug_*.log files
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'banggood_import_debug_*.log') ?: [] as $file) {
        if (!is_file($file)) { $missing++; continue; }
        if (!is_writable($file)) { $skipped++; continue; }
        if (@unlink($file)) $deleted++;
        else $skipped++;
    }

    // Delete optional cron log file (if it exists)
    $cronLog = $dir . DIRECTORY_SEPARATOR . 'banggood_cron.log';
    if (is_file($cronLog)) {
        if (is_writable($cronLog) && @unlink($cronLog)) $deleted++;
        else $skipped++;
    }
}

echo "Deleted={$deleted} Skipped={$skipped} Missing={$missing}\n";
exit(0);

