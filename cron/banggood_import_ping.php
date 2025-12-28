<?php
/**
 * Banggood Import - API ping test (OpenCart 3.x)
 *
 * Purpose:
 * - Verify PHP/cURL connectivity from the server to Banggood API endpoints
 * - Helpful when CLI `curl` works but PHP cURL times out (often IPv6 routing)
 *
 * Usage:
 *   php cron/banggood_import_ping.php --admin-dir=admin
 *   php cron/banggood_import_ping.php --admin-dir=admin --force-ipv4=1
 *
 * Notes:
 * - This does NOT require valid Banggood credentials; it only pings the API host and prints HTTP + timing.
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
        if (strpos($a, $prefix) === 0) return substr($a, strlen($prefix));
    }
    return $default;
}

$adminDir = (string)bg_arg($argv, 'admin-dir', 'admin');
$adminDir = trim($adminDir, "/ \t\n\r\0\x0B");
if ($adminDir === '') $adminDir = 'admin';

$forceIpv4 = bg_arg($argv, 'force-ipv4', '0');
$forceIpv4 = ($forceIpv4 === '1' || strtolower((string)$forceIpv4) === 'true');

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

// Banggood base URL (default used by the module)
$baseUrl = 'https://api.banggood.com/';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl);
curl_setopt($ch, CURLOPT_NOBODY, 1);
curl_setopt($ch, CURLOPT_HEADER, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_USERAGENT, 'OpenCart-Banggood-Ping');

if ($forceIpv4 && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
}
if (defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_1_1')) {
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
}

$res = curl_exec($ch);
if ($res === false) {
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    fwrite(STDERR, "PHP cURL error ({$errno}): {$err}\n");
    exit(2);
}

$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$total = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
$connect = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
$primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
curl_close($ch);

echo "OK http_code={$code} connect_time={$connect}s total_time={$total}s primary_ip={$primaryIp} force_ipv4=" . ($forceIpv4 ? '1' : '0') . "\n";
exit(0);

