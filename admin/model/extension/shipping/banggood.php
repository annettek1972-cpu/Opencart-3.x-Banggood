<?php
class ModelExtensionShippingBanggood extends Model {
    private $token_cache_file;

    public function __construct($registry) {
        parent::__construct($registry);
        $this->token_cache_file = DIR_STORAGE . 'banggood_api.token.php';
    }

    public function testShipments($params) {
        $config = $this->getBanggoodConfig();
        $product_id = isset($params['product_id']) ? trim((string)$params['product_id']) : '';
        $warehouse = isset($params['warehouse']) ? trim((string)$params['warehouse']) : '';
        $country = isset($params['country']) ? trim((string)$params['country']) : '';
        $poa_id = isset($params['poa_id']) ? trim((string)$params['poa_id']) : '';
        $quantity = isset($params['quantity']) ? (int)$params['quantity'] : 1;
        if ($quantity < 1) $quantity = 1;

        if ($product_id === '' || $warehouse === '' || $country === '') {
            throw new Exception('product_id, warehouse, and country are required');
        }

        $req = array(
            'product_id' => $product_id,
            'warehouse' => $warehouse,
            'country' => $country,
            'quantity' => $quantity,
            'lang' => $config['lang'],
            'currency' => $config['currency']
        );
        if ($poa_id !== '') $req['poa_id'] = $poa_id;

        $resp = $this->apiRequest($config, 'product/getShipments', 'GET', $req);

        return array(
            'request' => $req,
            'response' => $resp
        );
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

    protected function apiRequestRaw($config, $task, $method = 'GET', $params = array()) {
        if (preg_match('#^https?://#i', $task)) $url = $task;
        else $url = rtrim($config['base_url'], '/') . '/' . ltrim($task, '/');
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

    protected function clearAccessTokenCache() {
        if (is_file($this->token_cache_file)) @unlink($this->token_cache_file);
    }
}
