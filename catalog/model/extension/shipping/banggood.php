<?php
class ModelExtensionShippingBanggood extends Model {
    const PRODUCT_CODE_PREFIX = 'BBC-';
    const LEGACY_PRODUCT_CODE_PREFIX = 'BG-';

    private $token_cache_file;

    public function __construct($registry) {
        parent::__construct($registry);
        $this->token_cache_file = DIR_STORAGE . 'banggood_api.token.php';
    }

    public function getQuote($address) {
        $this->load->language('extension/shipping/banggood');

        if (!$this->config->get('shipping_banggood_status')) {
            return array();
        }

        $country = $this->resolveCountryName($address);
        if ($country === '') {
            return array(
                'code' => 'banggood',
                'title' => $this->language->get('text_title'),
                'quote' => array(),
                'sort_order' => (int)$this->config->get('shipping_banggood_sort_order'),
                'error' => $this->language->get('error_country')
            );
        }

        $products = $this->cart->getProducts();
        if (empty($products)) return array();

        $config = $this->getBanggoodConfig();
        $cacheDays = $this->getCacheDays();

        $details = array();
        $totalCost = 0.0;

        foreach ($products as $product) {
            $bg_id = $this->extractBanggoodId($product);
            if ($bg_id === '') continue;

            $quantity = isset($product['quantity']) ? (int)$product['quantity'] : 1;
            if ($quantity < 1) $quantity = 1;

            list($warehouse, $poa_id) = $this->resolveWarehouseAndPoa($product, $bg_id);
            if ($warehouse === '') continue;

            $resp = $this->getShipmentsCached($bg_id, $warehouse, $country, $poa_id, $quantity, $config, $cacheDays);
            if (empty($resp) || !is_array($resp)) continue;

            $shipment_list = array();
            if (!empty($resp['shipment_list']) && is_array($resp['shipment_list'])) {
                $shipment_list = $resp['shipment_list'];
            } elseif (!empty($resp['data']['shipment_list']) && is_array($resp['data']['shipment_list'])) {
                $shipment_list = $resp['data']['shipment_list'];
            }

            if (empty($shipment_list)) continue;

            $best = null;
            foreach ($shipment_list as $s) {
                $fee = $this->parseShipFee(isset($s['shipfee']) ? $s['shipfee'] : null);
                if ($best === null || $fee < $best['fee']) {
                    $best = array(
                        'fee' => $fee,
                        'name' => isset($s['shipmethod_name']) ? (string)$s['shipmethod_name'] : (isset($s['shipmethodcode']) ? (string)$s['shipmethodcode'] : 'Shipping'),
                        'code' => isset($s['shipmethod_code']) ? (string)$s['shipmethod_code'] : (isset($s['shipmethodcode']) ? (string)$s['shipmethodcode'] : '')
                    );
                }
            }

            if ($best === null) continue;

            $totalCost += (float)$best['fee'];
            $productName = isset($product['name']) ? $product['name'] : $bg_id;
            $details[] = $productName . ': ' . $best['name'] . ' ' . $config['currency'] . number_format((float)$best['fee'], 2);
        }

        if (empty($details)) {
            return array();
        }

        $detailText = implode(' | ', $details);
        if (strlen($detailText) > 180) {
            $detailText = substr($detailText, 0, 176) . '...';
        }

        $quote = array(
            'code' => 'banggood.banggood',
            'title' => $this->language->get('text_title') . ' - ' . $detailText,
            'cost' => $totalCost,
            'tax_class_id' => 0,
            'text' => $this->currency->format($totalCost, $this->session->data['currency'])
        );

        return array(
            'code' => 'banggood',
            'title' => $this->language->get('text_title'),
            'quote' => array('banggood' => $quote),
            'sort_order' => (int)$this->config->get('shipping_banggood_sort_order'),
            'error' => ''
        );
    }

    protected function resolveCountryName($address) {
        if (!empty($address['country'])) return strtolower(trim((string)$address['country']));
        if (!empty($address['country_id'])) {
            try {
                $this->load->model('localisation/country');
                $info = $this->model_localisation_country->getCountry($address['country_id']);
                if (!empty($info['name'])) return strtolower(trim((string)$info['name']));
            } catch (Exception $e) {}
        }
        return '';
    }

    protected function extractBanggoodId(array $product) {
        if (!empty($product['model'])) {
            $model = (string)$product['model'];
            if (stripos($model, self::PRODUCT_CODE_PREFIX) === 0) {
                return trim(substr($model, strlen(self::PRODUCT_CODE_PREFIX)));
            }
            if (stripos($model, self::LEGACY_PRODUCT_CODE_PREFIX) === 0) {
                return trim(substr($model, strlen(self::LEGACY_PRODUCT_CODE_PREFIX)));
            }
        }
        return '';
    }

    protected function resolveWarehouseAndPoa(array $product, $bg_id) {
        $warehouse = '';
        $poa_id = '';

        $option_value_ids = array();
        if (!empty($product['option']) && is_array($product['option'])) {
            foreach ($product['option'] as $opt) {
                if (!empty($opt['name']) && strtolower(trim((string)$opt['name'])) === 'ship from') {
                    $warehouse = isset($opt['value']) ? trim((string)$opt['value']) : '';
                }
                if (!empty($opt['option_value_id'])) {
                    $option_value_ids[] = (int)$opt['option_value_id'];
                }
            }
        }

        if (empty($warehouse)) {
            $cfg_wh = (string)$this->config->get('module_banggood_import_preferred_warehouse');
            if ($cfg_wh !== '') $warehouse = trim($cfg_wh);
        }

        if (empty($warehouse)) {
            try {
                $q = $this->db->query("SELECT DISTINCT warehouse_key FROM `" . DB_PREFIX . "bg_poa_warehouse_map` WHERE bg_id = '" . $this->db->escape((string)$bg_id) . "' LIMIT 1");
                if ($q && $q->num_rows) $warehouse = (string)$q->row['warehouse_key'];
            } catch (Exception $e) {}
        }

        if (!empty($option_value_ids)) {
            // Try product_variant table first
            try {
                $ids = array();
                foreach ($option_value_ids as $v) { if ($v > 0) $ids[] = (int)$v; }
                sort($ids, SORT_NUMERIC);
                $option_key = implode('|', $ids);
                $qv = $this->db->query("SELECT bg_poa_ids FROM `" . DB_PREFIX . "product_variant` WHERE bg_id = '" . $this->db->escape((string)$bg_id) . "' AND option_key = '" . $this->db->escape($option_key) . "' LIMIT 1");
                if ($qv && $qv->num_rows && !empty($qv->row['bg_poa_ids'])) {
                    $poa_id = $this->extractFirstId((string)$qv->row['bg_poa_ids']);
                }
            } catch (Exception $e) {}

            // Fallback: map by option_value_id via bg_poa_map
            if ($poa_id === '') {
                try {
                    $in = implode(',', array_map('intval', $option_value_ids));
                    $qm = $this->db->query("SELECT poa_id FROM `" . DB_PREFIX . "bg_poa_map` WHERE bg_id = '" . $this->db->escape((string)$bg_id) . "' AND option_value_id IN (" . $in . ") LIMIT 1");
                    if ($qm && $qm->num_rows && !empty($qm->row['poa_id'])) {
                        $poa_id = (string)$qm->row['poa_id'];
                    }
                } catch (Exception $e) {}
            }
        }

        return array($warehouse, $poa_id);
    }

    protected function extractFirstId($raw) {
        $raw = (string)$raw;
        if ($raw === '') return '';
        $parts = preg_split('/[,\|]/', $raw);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') return $p;
        }
        return '';
    }

    protected function parseShipFee($raw) {
        if ($raw === null) return 0.0;
        $num = (float)str_replace(',', '', preg_replace('/[^\d\.\-]/', '', (string)$raw));
        return $num;
    }

    protected function getCacheDays() {
        $days = (int)$this->config->get('shipping_banggood_cache_days');
        if ($days <= 0) $days = 7;
        if ($days < 1) $days = 1;
        return $days;
    }

    protected function getShipmentsCached($bg_id, $warehouse, $country, $poa_id, $quantity, $config, $cacheDays) {
        $this->ensureShipmentsCacheTableExists();
        $bg_id = (string)$bg_id;
        $warehouse = trim((string)$warehouse);
        $country = trim((string)$country);
        $poa_id = trim((string)$poa_id);
        $quantity = (int)$quantity;
        $currency = (string)$config['currency'];
        $lang = (string)$config['lang'];

        $row = $this->getShipmentCacheRow($bg_id, $warehouse, $country, $poa_id, $quantity, $currency, $lang);
        if ($row && !empty($row['fetched_at'])) {
            $ageDays = (time() - strtotime($row['fetched_at'])) / 86400;
            if ($ageDays <= $cacheDays) {
                $decoded = json_decode((string)$row['response_json'], true);
                if (is_array($decoded)) return $decoded;
            }
        }

        $params = array(
            'product_id' => $bg_id,
            'warehouse' => $warehouse,
            'country' => $country,
            'quantity' => $quantity,
            'lang' => $lang,
            'currency' => $currency
        );
        if ($poa_id !== '') $params['poa_id'] = $poa_id;

        $resp = $this->apiRequest($config, 'product/getShipments', 'GET', $params);
        $json = json_encode($resp, JSON_UNESCAPED_UNICODE);
        $this->saveShipmentCacheRow($bg_id, $warehouse, $country, $poa_id, $quantity, $currency, $lang, $json);
        return $resp;
    }

    protected function ensureShipmentsCacheTableExists() {
        $tbl = DB_PREFIX . "bg_shipments_cache";
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . $tbl . "` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `bg_product_id` varchar(64) NOT NULL,
            `warehouse` varchar(120) NOT NULL,
            `country` varchar(120) NOT NULL,
            `poa_id` varchar(120) DEFAULT '',
            `quantity` int(11) DEFAULT 1,
            `currency` varchar(10) DEFAULT '',
            `lang` varchar(10) DEFAULT '',
            `response_json` longtext,
            `fetched_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_bg_ship` (`bg_product_id`,`warehouse`,`country`,`poa_id`,`quantity`,`currency`,`lang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    }

    protected function getShipmentCacheRow($bg_id, $warehouse, $country, $poa_id, $quantity, $currency, $lang) {
        $tbl = DB_PREFIX . "bg_shipments_cache";
        $poa_id = (string)$poa_id;
        $sql = "SELECT * FROM `" . $tbl . "`
            WHERE `bg_product_id` = '" . $this->db->escape($bg_id) . "'
              AND `warehouse` = '" . $this->db->escape($warehouse) . "'
              AND `country` = '" . $this->db->escape($country) . "'
              AND `poa_id` = '" . $this->db->escape($poa_id) . "'
              AND `quantity` = '" . (int)$quantity . "'
              AND `currency` = '" . $this->db->escape($currency) . "'
              AND `lang` = '" . $this->db->escape($lang) . "'
            LIMIT 1";
        $qr = $this->db->query($sql);
        if ($qr && $qr->num_rows) return $qr->row;
        return null;
    }

    protected function saveShipmentCacheRow($bg_id, $warehouse, $country, $poa_id, $quantity, $currency, $lang, $json) {
        $tbl = DB_PREFIX . "bg_shipments_cache";
        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO `" . $tbl . "`
            (`bg_product_id`,`warehouse`,`country`,`poa_id`,`quantity`,`currency`,`lang`,`response_json`,`fetched_at`)
            VALUES ('" . $this->db->escape($bg_id) . "','" . $this->db->escape($warehouse) . "','" . $this->db->escape($country) . "','" . $this->db->escape((string)$poa_id) . "','" . (int)$quantity . "','" . $this->db->escape($currency) . "','" . $this->db->escape($lang) . "','" . $this->db->escape((string)$json) . "','" . $this->db->escape($now) . "')
            ON DUPLICATE KEY UPDATE
              `response_json` = VALUES(`response_json`),
              `fetched_at` = VALUES(`fetched_at`)";
        $this->db->query($sql);
    }

    protected function getBanggoodConfig() {
        $base_url = $this->config->get('module_banggood_import_base_url');
        if (!$base_url) $base_url = $this->config->get('banggood_import_base_url');
        if (!$base_url) $base_url = 'https://api.banggood.com';

        $app_id = $this->config->get('module_banggood_import_app_id');
        if (!$app_id) $app_id = $this->config->get('banggood_import_app_id');
        if (!$app_id) $app_id = '';

        $app_secret = $this->config->get('module_banggood_import_app_secret');
        if (!$app_secret) $app_secret = $this->config->get('banggood_import_app_secret');
        if (!$app_secret) $app_secret = '';

        $lang = $this->config->get('module_banggood_import_lang');
        if (!$lang) $lang = $this->config->get('config_language');
        if (!$lang) $lang = 'en';

        $currency = $this->config->get('module_banggood_import_currency');
        if (!$currency) $currency = $this->config->get('config_currency');
        if (!$currency) $currency = 'USD';

        return array(
            'base_url'   => trim($base_url, " \t\n\r\0\x0B/"),
            'app_id'     => (string)$app_id,
            'app_secret' => (string)$app_secret,
            'lang'       => (string)$lang,
            'currency'   => (string)$currency
        );
    }

    protected function getCurlTimeoutSeconds() {
        $t = (int)$this->config->get('module_banggood_import_curl_timeout');
        if ($t <= 0) $t = 180;
        if ($t < 30) $t = 30;
        return $t;
    }

    protected function getCurlConnectTimeoutSeconds() {
        $t = (int)$this->config->get('module_banggood_import_curl_connect_timeout');
        if ($t <= 0) $t = 20;
        if ($t < 5) $t = 5;
        return $t;
    }

    protected function apiRequest($config, $task, $method = 'GET', $params = array()) {
        $access_token = $this->getAccessToken($config);
        if ($task !== 'getAccessToken') {
            $params['access_token'] = $access_token;
            if (empty($params['lang'])) $params['lang'] = $config['lang'];
            if (empty($params['currency'])) $params['currency'] = $config['currency'];
        }

        $urlBase = rtrim($config['base_url'], '/') . '/';
        $url = $urlBase . ltrim($task, '/');
        $attempt = 0; $maxAttempts = 3;
        do {
            $attempt++;
            $reqUrl = $url;
            $ch = curl_init();
            if (strtoupper($method) === 'GET') {
                $query = http_build_query($params, '', '&');
                $reqUrl = $url . '?' . $query;
            }
            curl_setopt($ch, CURLOPT_URL, $reqUrl);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_USERAGENT, isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'OpenCart-Banggood-Client');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$this->getCurlConnectTimeoutSeconds());
            curl_setopt($ch, CURLOPT_TIMEOUT, (int)$this->getCurlTimeoutSeconds());
            if (defined('CURLOPT_NOSIGNAL')) curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            if (strtoupper($method) === 'POST') {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&'));
            }
            $result = curl_exec($ch);
            if ($result === false) {
                $errno = curl_errno($ch);
                $err = curl_error($ch);
                curl_close($ch);
                $transient = array(
                    CURLE_OPERATION_TIMEDOUT,
                    CURLE_COULDNT_CONNECT,
                    CURLE_COULDNT_RESOLVE_HOST,
                    CURLE_COULDNT_RESOLVE_PROXY,
                    CURLE_RECV_ERROR,
                    CURLE_SEND_ERROR,
                    CURLE_GOT_NOTHING
                );
                if ($attempt < $maxAttempts && in_array($errno, $transient, true)) {
                    usleep(300000);
                    continue;
                }
                throw new Exception('Banggood API curl error: ' . $err);
            }
            curl_close($ch);
            $data = json_decode($result, true);
            if (!is_array($data)) {
                if ($attempt < $maxAttempts) {
                    usleep(200000);
                    continue;
                }
                throw new Exception('Banggood API returned invalid JSON.');
            }

            if (isset($data['code']) && (int)$data['code'] === 21020 && $attempt < $maxAttempts) {
                $this->clearAccessTokenCache();
                $access_token = $this->getAccessToken($config);
                if ($task !== 'getAccessToken') {
                    $params['access_token'] = $access_token;
                    if (empty($params['lang'])) $params['lang'] = $config['lang'];
                    if (empty($params['currency'])) $params['currency'] = $config['currency'];
                }
                continue;
            }

            if (isset($data['code']) && (int)$data['code'] !== 0) {
                $msg = isset($data['msg']) ? $data['msg'] : (isset($data['message']) ? $data['message'] : '');
                throw new Exception('Banggood API error: code=' . $data['code'] . ' msg=' . $msg);
            }
            return $data;
        } while ($attempt < $maxAttempts);

        throw new Exception('Banggood API request failed after retry.');
    }

    protected function apiRequestRaw($config, $task, $method = 'GET', $params = array()) {
        if (preg_match('#^https?://#i', $task)) {
            $url = $task;
        } else {
            if (strpos($task, '/') === 0) $url = rtrim($config['base_url'], '/') . $task;
            else $url = rtrim($config['base_url'], '/') . '/' . ltrim($task, '/');
        }
        $attempt = 0; $maxAttempts = 3;
        do {
            $attempt++;
            $reqUrl = $url;
            if (strtoupper($method) === 'GET' && !empty($params)) {
                $reqUrl .= (strpos($reqUrl, '?') === false ? '?' : '&') . http_build_query($params, '', '&');
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $reqUrl);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_USERAGENT, isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'OpenCart-Banggood-Client');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$this->getCurlConnectTimeoutSeconds());
            curl_setopt($ch, CURLOPT_TIMEOUT, (int)$this->getCurlTimeoutSeconds());
            if (defined('CURLOPT_NOSIGNAL')) curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            if (strtoupper($method) === 'POST') {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&'));
            }
            $result = curl_exec($ch);
            if ($result === false) {
                $errno = curl_errno($ch);
                $err = curl_error($ch);
                curl_close($ch);
                $transient = array(
                    CURLE_OPERATION_TIMEDOUT,
                    CURLE_COULDNT_CONNECT,
                    CURLE_COULDNT_RESOLVE_HOST,
                    CURLE_COULDNT_RESOLVE_PROXY,
                    CURLE_RECV_ERROR,
                    CURLE_SEND_ERROR,
                    CURLE_GOT_NOTHING
                );
                if ($attempt < $maxAttempts && in_array($errno, $transient, true)) {
                    usleep(300000);
                    continue;
                }
                throw new Exception('Banggood API curl error: ' . $err);
            }
            curl_close($ch);
            $data = json_decode($result, true);
            if (is_array($data)) return $data;
            return $result;
        } while ($attempt < $maxAttempts);

        throw new Exception('Banggood API request failed after retry.');
    }

    protected function getAccessToken($config) {
        if (is_file($this->token_cache_file)) {
            $accessTokenArr = @include($this->token_cache_file);
            if (is_array($accessTokenArr) && !empty($accessTokenArr['accessToken']) &&
                !empty($accessTokenArr['expireTime']) && (int)$accessTokenArr['expireTime'] > time()) {
                return $accessTokenArr['accessToken'];
            }
        }
        $task = 'getAccessToken';
        $params = array('app_id' => $config['app_id'], 'app_secret' => $config['app_secret']);
        $data = $this->apiRequestRaw($config, $task, 'GET', $params);
        if (!is_array($data)) {
            throw new Exception('Banggood getAccessToken returned invalid response');
        }
        if (!isset($data['code']) || (int)$data['code'] !== 0) {
            $msg = isset($data['msg']) ? $data['msg'] : (isset($data['message']) ? $data['message'] : '');
            throw new Exception('Banggood getAccessToken error: code=' . (isset($data['code']) ? $data['code'] : 'unknown') . ' msg=' . $msg);
        }
        if (empty($data['access_token']) || empty($data['expires_in'])) throw new Exception('Banggood getAccessToken returned invalid data');
        $expireTime = time() + (int)$data['expires_in'];
        $accessTokenArr = array('accessToken' => $data['access_token'], 'expireTime' => $expireTime, 'expireDateTime' => date('Y-m-d H:i:s', $expireTime));
        $cacheStr = "<?php\nreturn " . var_export($accessTokenArr, true) . ";";
        @file_put_contents($this->token_cache_file, $cacheStr);
        return $data['access_token'];
    }

    protected function clearAccessTokenCache() {
        if (is_file($this->token_cache_file)) @unlink($this->token_cache_file);
    }
}
