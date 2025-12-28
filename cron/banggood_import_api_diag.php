<?php
/**
 * Banggood Import - API diagnostics (OpenCart 3.x)
 *
 * Why:
 * - Your server can reach https://api.banggood.com/ (HEAD 200),
 *   but the importer times out on real API calls (0 bytes received after 30s).
 * - This script reproduces the same API calls the importer uses and prints timings.
 *
 * Usage:
 *   php cron/banggood_import_api_diag.php --admin-dir=admin
 *   php cron/banggood_import_api_diag.php --admin-dir=admin --force-ipv4=1
 *   php cron/banggood_import_api_diag.php --admin-dir=admin --cat-id=59
 *
 * Output:
 * - HTTP code, primary_ip, connect/total times, bytes received
 * - First ~500 chars of response body (sanitized)
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

$catId = (string)bg_arg($argv, 'cat-id', '59');
$catId = trim($catId);
if ($catId === '') $catId = '59';

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

if (!defined('DB_PREFIX')) {
    fwrite(STDERR, "DB constants not loaded from admin/config.php\n");
    exit(1);
}

// Minimal DB connect (no OpenCart bootstrap needed)
$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);

function setting(DB $db, string $key, $default = '') {
    $q = $db->query("SELECT `value`,`serialized` FROM `" . DB_PREFIX . "setting` WHERE `store_id`=0 AND `key`='" . $db->escape($key) . "' LIMIT 1");
    if (!$q || !$q->num_rows) return $default;
    $row = $q->row;
    $val = (string)$row['value'];
    $ser = !empty($row['serialized']);
    if ($ser) {
        $d = json_decode($val, true);
        return $d !== null ? $d : $default;
    }
    return $val;
}

$baseUrl = (string)setting($db, 'module_banggood_import_base_url', 'https://api.banggood.com');
$appId = (string)setting($db, 'module_banggood_import_app_id', '');
$appSecret = (string)setting($db, 'module_banggood_import_app_secret', '');
$lang = (string)setting($db, 'module_banggood_import_lang', 'en');
$currency = (string)setting($db, 'module_banggood_import_currency', 'USD');

$baseUrl = rtrim(trim($baseUrl), "/ \t\n\r\0\x0B");
if ($baseUrl === '') $baseUrl = 'https://api.banggood.com';

echo "Config base_url={$baseUrl} app_id=" . ($appId !== '' ? '[set]' : '[EMPTY]') . " app_secret=" . ($appSecret !== '' ? '[set]' : '[EMPTY]') . " lang={$lang} currency={$currency}\n";

function curl_call(string $url, bool $forceIpv4): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'OpenCart-Banggood-Diag');
    if (defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_1_1')) {
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    }
    if ($forceIpv4 && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }

    $body = curl_exec($ch);
    $err = $body === false ? curl_error($ch) : '';
    $errno = $body === false ? curl_errno($ch) : 0;
    $info = [
        'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'total_time' => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
        'connect_time' => curl_getinfo($ch, CURLINFO_CONNECT_TIME),
        'primary_ip' => curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        'size_download' => curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD),
    ];
    curl_close($ch);

    return [
        'ok' => $body !== false,
        'errno' => $errno,
        'error' => $err,
        'info' => $info,
        'body' => is_string($body) ? $body : '',
    ];
}

// 1) getAccessToken (what the importer calls first)
$tokenUrl = $baseUrl . '/getAccessToken?' . http_build_query(['app_id' => $appId, 'app_secret' => $appSecret], '', '&');
echo "\n== getAccessToken ==\n";
$r1 = curl_call($tokenUrl, $forceIpv4);
echo "ok=" . ($r1['ok'] ? '1' : '0') . " http=" . $r1['info']['http_code'] . " ip=" . ($r1['info']['primary_ip'] ?: '-') . " connect=" . $r1['info']['connect_time'] . "s total=" . $r1['info']['total_time'] . "s bytes=" . $r1['info']['size_download'] . " force_ipv4=" . ($forceIpv4 ? '1' : '0') . "\n";
if (!$r1['ok']) {
    echo "curl_errno=" . $r1['errno'] . " error=" . $r1['error'] . "\n";
    exit(2);
}
$snippet1 = substr(preg_replace('/\s+/', ' ', $r1['body']), 0, 500);
echo "body_snippet=" . $snippet1 . "\n";

$token = '';
$j1 = json_decode($r1['body'], true);
if (is_array($j1) && !empty($j1['access_token'])) $token = (string)$j1['access_token'];
elseif (is_array($j1) && isset($j1['data']['access_token'])) $token = (string)$j1['data']['access_token'];

if ($token === '') {
    echo "No access_token extracted; cannot test product/getProductList.\n";
    exit(0);
}

// 2) product/getProductList (same endpoint Fetch uses)
$listUrl = $baseUrl . '/product/getProductList?' . http_build_query([
    'access_token' => $token,
    'cat_id' => $catId,
    'page' => 1,
    'pagesize' => 1,
    'lang' => $lang,
    'currency' => $currency,
], '', '&');

echo "\n== product/getProductList (pagesize=1) ==\n";
$r2 = curl_call($listUrl, $forceIpv4);
echo "ok=" . ($r2['ok'] ? '1' : '0') . " http=" . $r2['info']['http_code'] . " ip=" . ($r2['info']['primary_ip'] ?: '-') . " connect=" . $r2['info']['connect_time'] . "s total=" . $r2['info']['total_time'] . "s bytes=" . $r2['info']['size_download'] . " force_ipv4=" . ($forceIpv4 ? '1' : '0') . "\n";
if (!$r2['ok']) {
    echo "curl_errno=" . $r2['errno'] . " error=" . $r2['error'] . "\n";
    exit(3);
}
$snippet2 = substr(preg_replace('/\s+/', ' ', $r2['body']), 0, 500);
echo "body_snippet=" . $snippet2 . "\n";
exit(0);

