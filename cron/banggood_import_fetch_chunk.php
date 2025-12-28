<?php
/**
 * Banggood Import - cron fetch/import chunk runner (OpenCart 3.x)
 *
 * Goal:
 * - Run the same "Fetch next N products" logic from cron/CLI
 * - Without needing an admin login/session/user_token
 * - Without changing existing module button behavior
 *
 * Usage:
 *   php cron/banggood_import_fetch_chunk.php --chunk-size=10
 *   php cron/banggood_import_fetch_chunk.php --chunk-size=10 --reset-cursor=1
 *
 * Notes:
 * - Must be placed in your OpenCart store root under /cron/
 * - Requires a standard OpenCart 3.x installation (admin/config.php + system/startup.php)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI only.\n";
    exit(1);
}

// --- args ---
function bg_arg(array $argv, string $name, $default = null) {
    $prefix = '--' . $name . '=';
    foreach ($argv as $a) {
        if (strpos($a, $prefix) === 0) {
            return substr($a, strlen($prefix));
        }
    }
    return $default;
}

$chunkSize = (int)bg_arg($argv, 'chunk-size', '10');
if ($chunkSize < 1) $chunkSize = 1;
if ($chunkSize > 500) $chunkSize = 500;

$resetCursor = bg_arg($argv, 'reset-cursor', '0');
$resetCursor = ($resetCursor === '1' || strtolower((string)$resetCursor) === 'true');

// Verbose output for debugging cron runs
$verbose = bg_arg($argv, 'verbose', '0');
$verbose = ($verbose === '1' || strtolower((string)$verbose) === 'true');

// In CLI, make sure we can run longer imports
@set_time_limit(0);

// How to import each product:
// - id: call model->importProductById($bg_product_id) (more complete: includes applyStocksToProduct)
// - url: call model->importProductUrl($url) (matches the "Import Product URL" path)
// - auto: if queue raw_json contains a URL use it, else fall back to a synthetic URL containing the id
$importMode = strtolower((string)bg_arg($argv, 'import-mode', 'id'));
if (!in_array($importMode, ['id', 'url', 'auto'], true)) $importMode = 'id';

// Ensure oc_product_variant + stock_status_token get refreshed.
// NOTE: In this module, variants are generated in importProductById() via applyStocksToProduct().
$ensureVariants = bg_arg($argv, 'ensure-variants', '1');
$ensureVariants = ($ensureVariants === '1' || strtolower((string)$ensureVariants) === 'true');

// If your admin folder is renamed, pass --admin-dir=your_admin_folder
$adminDir = (string)bg_arg($argv, 'admin-dir', 'admin');
$adminDir = trim($adminDir, "/ \t\n\r\0\x0B");
if ($adminDir === '') $adminDir = 'admin';

// --- bootstrap OpenCart (admin side) ---
$root = realpath(__DIR__ . '/..');
if (!$root) {
    fwrite(STDERR, "Unable to resolve store root.\n");
    exit(1);
}

$adminConfig = $root . '/' . $adminDir . '/config.php';
if (!is_file($adminConfig)) {
    fwrite(STDERR, "Missing admin config at: {$adminConfig}\n");
    fwrite(STDERR, "This script must live in your OpenCart store root under /cron/.\n");
    fwrite(STDERR, "If your admin folder is renamed, pass --admin-dir=YOUR_ADMIN_FOLDER\n");
    exit(1);
}

require_once $adminConfig;

// Ensure OpenCart VERSION constant exists (many OCMODs/models use it).
// In web requests, this is defined in index.php/admin/index.php; in cron/CLI it isn't.
if (!defined('VERSION')) {
    $version = null;
    $candidates = [
        $root . '/index.php',
        $root . '/' . $adminDir . '/index.php',
    ];
    foreach ($candidates as $vf) {
        if (!is_file($vf)) continue;
        $src = @file_get_contents($vf);
        if (!is_string($src) || $src === '') continue;
        if (preg_match("/define\\(\\s*'VERSION'\\s*,\\s*'([^']+)'\\s*\\)\\s*;/", $src, $m)) {
            $version = $m[1];
            break;
        }
        if (preg_match('/define\\(\\s*"VERSION"\\s*,\\s*"([^"]+)"\\s*\\)\\s*;/', $src, $m)) {
            $version = $m[1];
            break;
        }
    }
    if (!is_string($version) || $version === '') {
        // Safe fallback to avoid undefined constant notices.
        $version = '3.0.0.0';
    }
    define('VERSION', $version);
}

if (!defined('DIR_SYSTEM')) {
    fwrite(STDERR, "DIR_SYSTEM not defined after loading admin/config.php\n");
    exit(1);
}

$startup = rtrim(DIR_SYSTEM, '/\\') . '/startup.php';
if (!is_file($startup)) {
    fwrite(STDERR, "Missing startup.php at: {$startup}\n");
    exit(1);
}

require_once $startup;

// Registry + core services (minimal set needed by models)
$registry = new Registry();

$config = new Config();
$registry->set('config', $config);

// DB
try {
    $db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}
$registry->set('db', $db);

// Load settings into config (replicates startup/setting)
try {
    $q = $db->query("SELECT * FROM `" . DB_PREFIX . "setting` WHERE `store_id` = 0");
    foreach ($q->rows as $row) {
        $key = (string)$row['key'];
        $value = (string)$row['value'];
        if (!empty($row['serialized'])) {
            $config->set($key, json_decode($value, true));
        } else {
            $config->set($key, $value);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to load settings: " . $e->getMessage() . "\n");
    exit(1);
}

$loader = new Loader($registry);
$registry->set('load', $loader);

$request = new Request();
$registry->set('request', $request);

$response = new Response();
$registry->set('response', $response);

// Optional but common dependencies used by many models/helpers
try {
    $registry->set('event', new Event($registry));
} catch (Throwable $e) {
    // ignore
}
try {
    // Many core models expect cache to exist; file cache is the safest default for cron.
    $registry->set('cache', new Cache('file', 3600));
} catch (Throwable $e) {
    // ignore
}
try {
    // Some helpers/models reference session; create a minimal session for compatibility.
    $session = new Session('file');
    $session->start();
    $registry->set('session', $session);
} catch (Throwable $e) {
    // ignore
}
try {
    // OpenCart Log expects a filename (it prepends DIR_LOGS internally).
    // If error_filename is empty/misconfigured, Log would try to fopen() a directory and warn.
    $logFile = (string)$config->get('error_filename');
    $logFile = trim($logFile);
    if ($logFile === '' || substr($logFile, -1) === '/' || substr($logFile, -1) === '\\') {
        $logFile = 'banggood_cron.log';
    }
    // If the log path isn't writable (common when previous runs were as root), avoid warnings by using a null logger.
    $canLog = true;
    if (defined('DIR_LOGS')) {
        $logDir = (string)DIR_LOGS;
        $logPath = rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . $logFile;
        if (!is_dir($logDir) || !is_writable($logDir)) {
            $canLog = false;
        } elseif (file_exists($logPath) && !is_writable($logPath)) {
            $canLog = false;
        }
    }

    if ($canLog) {
        $registry->set('log', new Log($logFile));
    } else {
        // Minimal logger compatible with `$this->log->write(...)` calls in OC models.
        $registry->set('log', new class {
            public function write($message) { /* no-op */ }
        });
    }
} catch (Throwable $e) {
    // ignore
}

// Language (some core models expect $this->language to exist)
try {
    if (class_exists('Language')) {
        $langCode = (string)$config->get('config_language');
        $langCode = $langCode !== '' ? $langCode : 'en-gb';
        $language = new Language($langCode);
        // Load the base language pack file, e.g. admin/language/en-gb/en-gb.php
        if (method_exists($language, 'load')) {
            $language->load($langCode);
        }
        $registry->set('language', $language);
    }
} catch (Throwable $e) {
    // ignore
}

// --- helpers (mostly lifted from the controller behavior) ---
function bg_fetch_category_rows(DB $db, string $shortTable): array {
    $fullTable = DB_PREFIX . $shortTable;
    try {
        $q = $db->query("SHOW TABLES LIKE '" . $db->escape($fullTable) . "'");
        if (!$q->num_rows) return [];

        $cols = $db->query("SHOW COLUMNS FROM `" . $db->escape($fullTable) . "`");
        if (!$cols->num_rows) return [];

        $available = [];
        foreach ($cols->rows as $c) $available[] = $c['Field'];

        $idCandidates = ['bg_cat_id', 'cat_id', 'category_id', 'id', 'bgc_id'];
        $idCol = null;
        foreach ($idCandidates as $c) {
            if (in_array($c, $available, true)) {
                $idCol = $c;
                break;
            }
        }
        if (!$idCol) return [];

        $qr = $db->query("SELECT `" . $db->escape($idCol) . "` AS cat_id FROM `" . $db->escape($fullTable) . "` ORDER BY `" . $db->escape($idCol) . "`");
        return $qr->rows;
    } catch (Throwable $e) {
        return [];
    }
}

function bg_read_cursor(Config $config): array {
    $cursorRaw = $config->get('module_banggood_import_fetch_cursor');
    $cursor = ['category_index' => 0, 'page' => 1, 'offset' => 0];
    if (is_string($cursorRaw) && $cursorRaw !== '') {
        $decoded = @json_decode($cursorRaw, true);
        if (is_array($decoded)) {
            $cursor['category_index'] = isset($decoded['category_index']) ? max(0, (int)$decoded['category_index']) : 0;
            $cursor['page'] = isset($decoded['page']) ? max(1, (int)$decoded['page']) : 1;
            $cursor['offset'] = isset($decoded['offset']) ? max(0, (int)$decoded['offset']) : 0;
        }
    }
    return $cursor;
}

// --- run ---
try {
    // Models needed
    $loader->model('extension/module/banggood_import');
    $loader->model('setting/setting');

    /** @var ModelExtensionModuleBanggoodImport $bgModel */
    $bgModel = $registry->get('model_extension_module_banggood_import');
    /** @var ModelSettingSetting $settingModel */
    $settingModel = $registry->get('model_setting_setting');

    // Persist last cron status so admin UI can show it
    $bg_extract_code = function(string $message): int {
        if ($message === '') return 0;
        if (preg_match('/\bcode\s*=\s*(\d+)\b/i', $message, $m)) return (int)$m[1];
        if (preg_match('/\bcode\s*:\s*(\d+)\b/i', $message, $m)) return (int)$m[1];
        return 0;
    };

    $bg_write_cron_status = function(array $payload) use ($settingModel) {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if (method_exists($settingModel, 'editSettingValue')) {
                $settingModel->editSettingValue('module_banggood_import', 'module_banggood_import_cron_last_status', $json);
            } else {
                $cur = $settingModel->getSetting('module_banggood_import');
                if (!is_array($cur)) $cur = [];
                $cur['module_banggood_import_cron_last_status'] = $json;
                $settingModel->editSetting('module_banggood_import', $cur);
            }
        } catch (Throwable $e) {
            // ignore - cron should still output to CLI
        }
    };

    if ($resetCursor) {
        $cursorNew = json_encode(['category_index' => 0, 'page' => 1, 'offset' => 0]);
        if (method_exists($settingModel, 'editSettingValue')) {
            $settingModel->editSettingValue('module_banggood_import', 'module_banggood_import_fetch_cursor', $cursorNew);
        } else {
            $cur = $settingModel->getSetting('module_banggood_import');
            if (!is_array($cur)) $cur = [];
            $cur['module_banggood_import_fetch_cursor'] = $cursorNew;
            $settingModel->editSetting('module_banggood_import', $cur);
        }
        echo "Cursor reset.\n";
    }

    $cursor = bg_read_cursor($config);
    $category_index = (int)$cursor['category_index'];
    $page = (int)$cursor['page'];
    $offset = (int)$cursor['offset'];

    $rows = bg_fetch_category_rows($db, 'bg_category');
    if (!$rows) $rows = bg_fetch_category_rows($db, 'bg_category_import');
    if (!$rows) {
        $bg_write_cron_status([
            'ran_at' => gmdate('c'),
            'ok' => false,
            'source' => 'cron',
            'chunk_size' => $chunkSize,
            'message' => 'No Banggood categories available to iterate (bg_category / bg_category_import empty).',
        ]);
        fwrite(STDERR, "No Banggood categories available to iterate (bg_category / bg_category_import empty).\n");
        exit(2);
    }

    $total_categories = count($rows);
    $collected = [];

    $next_category_index = $category_index;
    $next_page = $page;
    $next_offset = $offset;
    $finished = false;

    // Banggood docs: 20 products max per page.
    $api_page_size = 20;

    for ($ci = $category_index; $ci < $total_categories && count($collected) < $chunkSize; $ci++) {
        $cat_id = isset($rows[$ci]['cat_id']) ? (string)$rows[$ci]['cat_id'] : '';
        if ($cat_id === '') continue;

        $currentPage = ($ci === $category_index) ? $page : 1;
        $currentOffset = ($ci === $category_index) ? $offset : 0;
        $page_total = 0;

        while (count($collected) < $chunkSize) {
            $res = $bgModel->fetchProductList($cat_id, $currentPage, $api_page_size);
            if (!$res || (!empty($res['errors']) && is_array($res['errors']))) {
                break;
            }

            $products = (!empty($res['products']) && is_array($res['products'])) ? $res['products'] : [];
            if (!$products) break;

            $remaining = $chunkSize - count($collected);
            $slice = array_slice($products, $currentOffset, $remaining);
            foreach ($slice as $p) {
                $collected[] = $p;
                if (count($collected) >= $chunkSize) break;
            }

            $page_total = isset($res['page_total']) ? (int)$res['page_total'] : 0;
            $currentOffset += count($slice);

            if ($currentOffset >= count($products)) {
                $currentPage++;
                $currentOffset = 0;
            }

            if ($page_total > 0 && $currentPage > $page_total) {
                break;
            }
        }

        if (count($collected) >= $chunkSize) {
            $next_category_index = $ci;
            $next_page = $currentPage;
            $next_offset = $currentOffset;

            // If we moved past the last page for this category, advance category.
            if ($page_total > 0 && $next_page > $page_total) {
                $next_category_index = $ci + 1;
                $next_page = 1;
                $next_offset = 0;
            }
            break;
        }

        // exhausted this category, move on to next
        $next_category_index = $ci + 1;
        $next_page = 1;
        $next_offset = 0;
    }

    if ($next_category_index >= $total_categories) {
        $finished = true;
    }

    // Persist fetched products into bg_fetched_products
    $persisted = (int)$bgModel->saveFetchedProducts($collected);

    // Import via queue (preferred):
    // - ensures status/attempts are updated atomically
    // - allows cron to continue processing even if fetch returns 0 but queue already has pending work
    $claimed = 0;
    $imported = 0;
    $import_errors = 0;
    $firstError = '';
    $created = 0;
    $updated = 0;
    $skipped = 0;

    $rowsToProcess = [];
    if (method_exists($bgModel, 'fetchPendingForProcessing')) {
        $rowsToProcess = $bgModel->fetchPendingForProcessing($chunkSize);
    } else {
        // Fallback: process only the collected list (older installs)
        foreach ($collected as $p) {
            $pid = isset($p['product_id']) ? (string)$p['product_id'] : '';
            if ($pid === '') continue;
            $rowsToProcess[] = ['bg_product_id' => $pid];
        }
    }

    $claimed = is_array($rowsToProcess) ? count($rowsToProcess) : 0;

    foreach ($rowsToProcess as $row) {
        $pid = isset($row['bg_product_id']) ? (string)$row['bg_product_id'] : '';
        if ($pid === '') continue;
        try {
            $res = null;
            $usedMode = $importMode;
            $variantSync = false;

            // Try to locate a URL in the persisted row (raw_json) when using auto/url.
            $url = '';
            if (($importMode === 'auto' || $importMode === 'url') && !empty($row['raw_json'])) {
                $raw = @json_decode((string)$row['raw_json'], true);
                if (is_array($raw)) {
                    foreach (['product_url', 'url', 'href', 'link'] as $k) {
                        if (!empty($raw[$k]) && is_string($raw[$k])) { $url = $raw[$k]; break; }
                    }
                }
            }

            if ($importMode === 'id') {
                $res = $bgModel->importProductById($pid);
            } else {
                // If we don't have a real URL in the queue, use a synthetic Banggood URL that still matches
                // extractProductIdFromUrl() regexes. This guarantees the URL-import pipeline is used.
                if ($url === '') $url = 'https://www.banggood.com/item/' . rawurlencode($pid) . '.html';
                if ($importMode === 'auto') $usedMode = ($url !== '' ? 'url' : 'id');
                $res = $bgModel->importProductUrl($url);
            }

            // IMPORTANT:
            // Your module only updates oc_product_variant inside importProductById() (via protected applyStocksToProduct()).
            // So if we imported via URL, run importProductById() after to refresh variants/stock tokens.
            if ($ensureVariants && $usedMode !== 'id') {
                try {
                    $bgModel->importProductById($pid);
                    $variantSync = true;
                } catch (Throwable $e) {
                    // If variant sync fails, treat as an import error so the queue is marked error.
                    throw $e;
                }
            }

            if (method_exists($bgModel, 'markFetchedProductImported')) $bgModel->markFetchedProductImported($pid);
            $imported++;

            // Normalize result reporting across importProductById() and importProductUrl()
            $r = '';
            if (is_array($res) && isset($res['result'])) {
                $r = (string)$res['result']; // created|updated|skip
            } elseif (is_array($res) && (isset($res['created']) || isset($res['updated']))) {
                $c = !empty($res['created']);
                $u = !empty($res['updated']);
                if ($c) $r = 'created';
                elseif ($u) $r = 'updated';
                else $r = 'skip';
            }

            if ($r === 'created') $created++;
            elseif ($r === 'updated') $updated++;
            elseif ($r === 'skip') $skipped++;

            if ($verbose) {
                fwrite(STDOUT, "Imported bg_product_id={$pid} mode={$usedMode}" . ($variantSync ? "+variantSync" : "") . " result=" . ($r !== '' ? $r : 'ok') . "\n");
            }
        } catch (Throwable $e) {
            if (method_exists($bgModel, 'markFetchedProductError')) $bgModel->markFetchedProductError($pid, $e->getMessage());
            $import_errors++;
            if ($firstError === '') $firstError = $e->getMessage();
            if ($verbose) {
                fwrite(STDERR, "ERROR bg_product_id={$pid} " . $e->getMessage() . "\n");
            }
        }
    }

    // Compute and persist next cursor (also included in cron status)
    $cursorNew = json_encode(['category_index' => (int)$next_category_index, 'page' => (int)$next_page, 'offset' => (int)$next_offset]);

    // Persist cron status for admin UI visibility
    $api_code = $firstError !== '' ? $bg_extract_code($firstError) : 0;
    $bg_write_cron_status([
        'ran_at' => gmdate('c'),
        'ok' => ($import_errors === 0),
        'source' => 'cron',
        'chunk_size' => (int)$chunkSize,
        'import_mode' => $importMode,
        'ensure_variants' => (bool)$ensureVariants,
        'fetched' => (int)count($collected),
        'persisted' => (int)$persisted,
        'claimed' => (int)$claimed,
        'imported' => (int)$imported,
        'created' => (int)$created,
        'updated' => (int)$updated,
        'skipped' => (int)$skipped,
        'errors' => (int)$import_errors,
        'first_error' => $firstError,
        'api_code' => (int)$api_code,
        'finished' => (bool)$finished,
        'next_cursor' => $cursorNew,
    ]);

    // Save updated cursor
    if (method_exists($settingModel, 'editSettingValue')) {
        $settingModel->editSettingValue('module_banggood_import', 'module_banggood_import_fetch_cursor', $cursorNew);
    } else {
        $cur = $settingModel->getSetting('module_banggood_import');
        if (!is_array($cur)) $cur = [];
        $cur['module_banggood_import_fetch_cursor'] = $cursorNew;
        $settingModel->editSetting('module_banggood_import', $cur);
    }

    echo "Fetched=" . count($collected) .
         " Persisted=" . $persisted .
         " Claimed=" . $claimed .
         " Imported=" . $imported .
         " (created=" . $created . " updated=" . $updated . " skipped=" . $skipped . ")" .
         " Errors=" . $import_errors .
         " Finished=" . ($finished ? "1" : "0") . "\n";
    if ($import_errors > 0 && $firstError !== '') {
        echo "FirstError=" . $firstError . "\n";
    }
    echo "NextCursor=" . $cursorNew . "\n";

    // exit code: non-zero if we imported nothing due to errors/fetch issue is not necessarily failure
    exit(0);
} catch (Throwable $e) {
    // Best-effort: write failing status if we can reach setting model
    try {
        if (isset($settingModel) && isset($bg_write_cron_status) && is_callable($bg_write_cron_status)) {
            $msg = $e->getMessage();
            $code = isset($bg_extract_code) && is_callable($bg_extract_code) ? (int)$bg_extract_code($msg) : 0;
            $bg_write_cron_status([
                'ran_at' => gmdate('c'),
                'ok' => false,
                'source' => 'cron',
                'chunk_size' => (int)$chunkSize,
                'message' => 'Cron failed: ' . $msg,
                'api_code' => (int)$code,
            ]);
        }
    } catch (Throwable $x) {
        // ignore
    }
    fwrite(STDERR, "Cron failed: " . $e->getMessage() . "\n");
    exit(1);
}
