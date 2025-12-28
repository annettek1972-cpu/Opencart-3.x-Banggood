<?php
class ModelExtensionModuleBanggoodImport extends Model {

    const PRODUCT_URL_REGEX_1 = '#/(?:product|item)/(\d+)#i';
    const PRODUCT_URL_REGEX_2 = '#-p-(\d+)\.html#i';
    // Prefix for OpenCart product model and local image folders/files
    const PRODUCT_CODE_PREFIX = 'BBC-';
    // Backward compatibility for older imports
    const LEGACY_PRODUCT_CODE_PREFIX = 'BG-';
    // All imported products should be assigned to this OpenCart category (never updated after first import)
    const FIXED_PRODUCT_CATEGORY_ID = 1;

    private $token_cache_file;
    public function __construct($registry) {
        parent::__construct($registry);
        $this->token_cache_file = DIR_STORAGE . 'banggood_api.token.php';
    }


  public function importVariantsFromCombinations($product_id, array $variants) {
        $language_id = (int)$this->config->get('config_language_id');

        $inserted = 0;
        $updated = 0;

        // Helper closures

        $normalizeKey = function($raw) {
            if ($raw === null) return '';
            // accept CSV or pipe-delimited, remove whitespace, keep numeric parts only, sort
            $parts = preg_split('/[,\|]/', (string)$raw);
            $clean = array();
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                // keep digits only (product_option_value ids are numeric)
                if (preg_match('/^\d+$/', $p)) $clean[] = (int)$p;
            }
            $clean = array_values(array_unique($clean));
            sort($clean, SORT_NUMERIC);
            return $clean ? implode('|', $clean) : '';
        };

        $normalizePoa = function($raw) {
            if ($raw === null) return null;
            $parts = preg_split('/[,\|]/', (string)$raw);
            $clean = array();
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                if (preg_match('/^\d+$/', $p)) $clean[] = (int)$p;
            }
            $clean = array_values(array_unique($clean));
            sort($clean, SORT_NUMERIC);
            return $clean ? implode('|', $clean) : null;
        };

        $extractBgIdFromSku = function($sku) {
            if (!$sku) return null;
            if (preg_match('/^(?:BBC|BG)-(\d+)/i', $sku, $m)) return (int)$m[1];
            return null;
        };

        // Build human option_text from product_option_value ids (product_option_value_id)
        $buildOptionText = function(array $ov_ids) use ($language_id) {
            $ov_ids = array_values(array_filter(array_map('intval', $ov_ids)));
            if (!$ov_ids) return '';

            $in = implode(',', $ov_ids);
            $sql = "SELECT pov.product_option_value_id, po.option_id, od.name AS option_name, ovd.name AS option_value_name
                    FROM " . DB_PREFIX . "product_option_value pov
                    LEFT JOIN " . DB_PREFIX . "product_option po ON pov.product_option_id = po.product_option_id
                    LEFT JOIN " . DB_PREFIX . "option_description od ON po.option_id = od.option_id AND od.language_id = " . (int)$language_id . "
                    LEFT JOIN " . DB_PREFIX . "option_value_description ovd ON pov.option_value_id = ovd.option_value_id AND ovd.language_id = " . (int)$language_id . "
                    WHERE pov.product_option_value_id IN (" . $in . ")";
            $res = $this->db->query($sql);
            $map = array();
            if ($res->num_rows) {
                foreach ($res->rows as $row) {
                    $id = (int)$row['product_option_value_id'];
                    $map[$id] = trim(((isset($row['option_name']) ? $row['option_name'] : '') . ': ' . (isset($row['option_value_name']) ? $row['option_value_name'] : '')));
                }
            }
            $parts = array();
            foreach ($ov_ids as $id) {
                $id = (int)$id;
                if (isset($map[$id]) && $map[$id] !== '') $parts[] = $map[$id];
                else $parts[] = (string)$id;
            }
            return implode(' / ', $parts);
        };

        foreach ($variants as $v) {
            // ensure structure
            $sku = isset($v['sku']) ? (string)$v['sku'] : '';
            $raw_key = isset($v['option_key']) ? $v['option_key'] : (isset($v['ov_ids']) ? (is_array($v['ov_ids']) ? implode(',', $v['ov_ids']) : $v['ov_ids']) : '');
            $canonical_key = $normalizeKey($raw_key);
            if ($canonical_key === '') {
                // skip invalid combinations
                continue;
            }

            $bg_poa_ids = isset($v['bg_poa_ids']) ? $normalizePoa($v['bg_poa_ids']) : null;
            $bg_id = isset($v['bg_id']) && $v['bg_id'] ? (int)$v['bg_id'] : $extractBgIdFromSku($sku);
            $quantity = isset($v['quantity']) ? (int)$v['quantity'] : 0;
            $price = isset($v['price']) ? (float)$v['price'] : 0.0;
            $stock_status_token = isset($v['stock_status_token']) ? $v['stock_status_token'] : null;
            $stock_status_id = isset($v['stock_status_id']) ? (int)$v['stock_status_id'] : null;

            // Build option_text when not supplied or when it looks numeric/CSV
            $option_text = isset($v['option_text']) ? (string)$v['option_text'] : '';
            $need_text = false;
            if ($option_text === '' || preg_match('/^[\d\|\-,\s]+$/', $option_text)) $need_text = true;
            if ($need_text) {
                $ov_ids = array();
                if (isset($v['ov_ids'])) {
                    if (is_array($v['ov_ids'])) $ov_ids = $v['ov_ids'];
                    else $ov_ids = preg_split('/[,\|]/', (string)$v['ov_ids']);
                } else {
                    // If only raw_key available (pipe list), use that
                    $ov_ids = preg_split('/[,\|]/', (string)$raw_key);
                }
                $ov_ids = array_map('trim', $ov_ids);
                $ov_ids = array_values(array_filter($ov_ids, function($x){ return $x !== ''; }));
                $option_text = $buildOptionText($ov_ids);
            }

            // Upsert: try to find existing variant by product_id + (sku OR option_key)
            $findSql = "SELECT variant_id FROM " . DB_PREFIX . "product_variant
                        WHERE product_id = '" . (int)$product_id . "'
                          AND (sku = '" . $this->db->escape($sku) . "' OR option_key = '" . $this->db->escape($canonical_key) . "')
                        LIMIT 1";
            $found = $this->db->query($findSql);

            if ($found->num_rows) {
                $variant_id = (int)$found->row['variant_id'];
                // Update existing
                $updateSql = "UPDATE " . DB_PREFIX . "product_variant SET
                              sku = '" . $this->db->escape($sku) . "',
                              option_key = '" . $this->db->escape($canonical_key) . "',
                              option_text = '" . $this->db->escape($option_text) . "',
                              quantity = '" . (int)$quantity . "',
                              stock_status_token = " . ($stock_status_token !== null ? "'" . $this->db->escape($stock_status_token) . "'" : "NULL") . ",
                              stock_status_id = " . ($stock_status_id !== null ? "'" . (int)$stock_status_id . "'" : "NULL") . ",
                              price = '" . (float)$price . "',
                              bg_poa_ids = " . ($bg_poa_ids !== null ? "'" . $this->db->escape($bg_poa_ids) . "'" : "NULL") . ",
                              bg_id = " . ($bg_id !== null ? "'" . (int)$bg_id . "'" : "NULL") . ",
                              date_modified = NOW()
                              WHERE variant_id = '" . $variant_id . "'";
                $this->db->query($updateSql);
                $updated++;
            } else {
                // Insert new row
                $insertSql = "INSERT INTO " . DB_PREFIX . "product_variant SET
                              product_id = '" . (int)$product_id . "',
                              sku = '" . $this->db->escape($sku) . "',
                              option_key = '" . $this->db->escape($canonical_key) . "',
                              option_text = '" . $this->db->escape($option_text) . "',
                              quantity = '" . (int)$quantity . "',
                              stock_status_token = " . ($stock_status_token !== null ? "'" . $this->db->escape($stock_status_token) . "'" : "NULL") . ",
                              stock_status_id = " . ($stock_status_id !== null ? "'" . (int)$stock_status_id . "'" : "NULL") . ",
                              price = '" . (float)$price . "',
                              bg_poa_ids = " . ($bg_poa_ids !== null ? "'" . $this->db->escape($bg_poa_ids) . "'" : "NULL") . ",
                              bg_id = " . ($bg_id !== null ? "'" . (int)$bg_id . "'" : "NULL") . ",
                              date_modified = NOW()";
                $this->db->query($insertSql);
                $inserted++;
            }
        } // end foreach

        return array('inserted' => $inserted, 'updated' => $updated);
    }
    /* -------------------------
       Helper: ensure mapping table exists (now includes poa_price)
       ------------------------- */
    protected function ensureBgPoaMapTableExists() {
        $tbl = DB_PREFIX . "bg_poa_map";
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . $tbl . "` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `bg_id` varchar(120) NOT NULL,
            `poa_id` varchar(120) NOT NULL,
            `poa_price` decimal(10,2) DEFAULT NULL,
            `option_value_id` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `bg_poa` (`bg_id`, `poa_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
    }
	
	protected function stripAllFormatting($html) {
    // Keep basic semantic formatting (p, br, ul, ol, li, headings, b/strong, i/em, u, a, img)
    // Remove presentational tags (font, span) and inline styling/attributes (style, class, id, on*)
    // Also fix missing space after colons in label:value fragments (e.g. "Size:S" -> "Size: S")
    if (empty($html) || !is_string($html)) return '';

    // Normalize entities
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // Wrap to have a single root we can reference
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="bg_strip_root">' . $html . '</div>');
    libxml_clear_errors();
    if (!$loaded) {
        // Fallback: strip tags but keep readable paragraphs
        $txt = trim(strip_tags($html));
        $txt = preg_replace('/\s{2,}/u', ' ', $txt);
        return '<p>' . htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $xpath = new DOMXPath($dom);

    // Remove style/script and comments
    foreach ($dom->getElementsByTagName('style') as $n) $n->parentNode->removeChild($n);
    foreach ($dom->getElementsByTagName('script') as $n) $n->parentNode->removeChild($n);
    foreach ($xpath->query('//comment()') as $c) $c->parentNode->removeChild($c);

    // Allowed tags and allowed attributes per tag
    $allowedTags = array('p','br','ul','ol','li','b','strong','i','em','u','h1','h2','h3','h4','h5','h6','a','img','table','thead','tbody','tr','td','th');
    $allowedAttrs = array(
        'a'   => array('href','title','target','rel'),
        'img' => array('src','alt','title','width','height'),
        'td'  => array('colspan','rowspan'),
        'th'  => array('colspan','rowspan')
    );

    // Helper: sanitize URI attrs (prevent javascript: etc.)
    $sanitizeUri = function($val) {
        if ($val === null) return '';
        $val = trim($val);
        // Allow data:images and http/https/file protocol; block javascript: and vbscript:
        $low = strtolower($val);
        if (strpos($low, 'javascript:') === 0 || strpos($low, 'vbscript:') === 0) return '';
        return $val;
    };

    // Unwrap presentational tags and remove disallowed tags/attributes
    // We'll iterate with XPath selecting all elements under root
    $root = $dom->getElementById('bg_strip_root');
    if (!$root) {
        $full = trim(preg_replace('/\s+/', ' ', $dom->textContent));
        return '<p>' . htmlspecialchars($full, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    // Collect nodes in document order (NodeList is live - collect first)
    $all = array();
    foreach ($xpath->query('//*[@id="bg_strip_root"]//*') as $n) $all[] = $n;

    foreach ($all as $node) {
        if (!$node instanceof DOMElement) continue;
        $tag = strtolower($node->nodeName);

        // Remove presentational wrapper tags but keep their content
        if (in_array($tag, array('span','font'))) {
            while ($node->firstChild) $node->parentNode->insertBefore($node->firstChild, $node);
            $node->parentNode->removeChild($node);
            continue;
        }

        // Convert div -> p to preserve paragraph semantics
        if ($tag === 'div') {
            $p = $dom->createElement('p');
            while ($node->firstChild) $p->appendChild($node->firstChild);
            $node->parentNode->replaceChild($p, $node);
            continue;
        }

        // If tag not allowed, unwrap (preserve text/children)
        if (!in_array($tag, $allowedTags)) {
            while ($node->firstChild) $node->parentNode->insertBefore($node->firstChild, $node);
            $node->parentNode->removeChild($node);
            continue;
        }

        // Remove disallowed attributes (keep only allowed set per tag)
        if ($node->hasAttributes()) {
            $attrsToRemove = array();
            foreach ($node->attributes as $a) {
                $an = strtolower($a->name);
                $keep = false;
                if (isset($allowedAttrs[$tag]) && in_array($an, $allowedAttrs[$tag])) $keep = true;
                // allow global aria and title/alt when present
                if (in_array($an, array('title','alt'))) $keep = true;
                if (!$keep) $attrsToRemove[] = $an;
            }
            foreach ($attrsToRemove as $an) $node->removeAttribute($an);
        }

        // Sanitize href/src values and remove any remaining event handlers
        if ($node->hasAttribute('href')) {
            $href = $node->getAttribute('href');
            $href = $sanitizeUri($href);
            if ($href === '') $node->removeAttribute('href'); else $node->setAttribute('href', $href);
        }
        if ($node->hasAttribute('src')) {
            $src = $node->getAttribute('src');
            $src = $sanitizeUri($src);
            if ($src === '') $node->removeAttribute('src'); else $node->setAttribute('src', $src);
        }

        // Remove any remaining attributes that start with 'on' (event handlers) or data-
        $rem = array();
        foreach ($node->attributes as $a) {
            $an = strtolower($a->name);
            if (strpos($an, 'on') === 0 || strpos($an, 'data-') === 0) $rem[] = $an;
        }
        foreach ($rem as $r) $node->removeAttribute($r);
    }

    // Fix label:value spacing inside text nodes beneath root
    // We'll walk text nodes and ensure a space after colon when followed immediately by a letter/number
    $textNodes = $xpath->query('//*[@id="bg_strip_root"]//text()');
    foreach ($textNodes as $tn) {
        $txt = $tn->nodeValue;
        // Avoid modifying scripts/URLs by only applying to short label-like patterns:
        // Replace "Label:Value" or "Label:ValueMore" -> "Label: Value"
        $txt = preg_replace('/([A-Za-z0-9\)\]\%])\:([A-Za-z0-9\%\(\[])/u', '$1: $2', $txt);
        // Also ensure there's a space after commas when no space
        $txt = preg_replace('/,([A-Za-z0-9])/u', ', $1', $txt);
        // Condense multiple spaces
        $txt = preg_replace('/\s{2,}/u', ' ', $txt);
        $tn->nodeValue = $txt;
    }

    // Build output HTML from children of root (preserve structure)
    $outParts = array();
    foreach ($root->childNodes as $child) {
        // SaveHTML will return full tag markup; avoid adding wrapper again
        $htmlPiece = $dom->saveHTML($child);
        if ($htmlPiece === null) continue;
        $htmlPiece = trim($htmlPiece);
        if ($htmlPiece === '') continue;
        $outParts[] = $htmlPiece;
    }

    $out = implode("\n", $outParts);

    // Final cleanup: remove empty tags like <p></p>
    $out = preg_replace('#<p>\s*</p>\s*#i', '', $out);

    return trim($out);
}
	

    /* -------------------------
       Helper: ensure warehouse-per-poa map table exists
       ------------------------- */
    protected function ensureBgPoaWarehouseMapTableExists() {
        $tbl = DB_PREFIX . "bg_poa_warehouse_map";
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . $tbl . "` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `bg_id` varchar(120) NOT NULL,
            `poa_id` varchar(120) NOT NULL,
            `warehouse_key` varchar(120) NOT NULL,
            `product_price` decimal(10,2) DEFAULT NULL,
            `price_modifier` decimal(10,2) DEFAULT NULL,
            `product_stock` int(11) DEFAULT NULL,
            `product_stock_msg` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `bg_poa_wh` (`bg_id`,`poa_id`,`warehouse_key`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
    }

    /* -------------------------
       Helper: ensure warehouse map table exists (optional, left for compatibility)
       ------------------------- */
    protected function ensureBgWarehouseMapTableExists() {
        $tbl = DB_PREFIX . "bg_warehouse_map";
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . $tbl . "` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `bg_id` varchar(120) NOT NULL,
            `warehouse_key` varchar(120) NOT NULL,
            `warehouse_label` varchar(255) DEFAULT NULL,
            `option_value_id` int(11) NOT NULL,
            `price_modifier` decimal(10,2) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `bg_wh` (`bg_id`,`warehouse_key`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
    }

    /* -------------------------
       NEW: Ensure bg_category table exists
       ------------------------- */
protected function ensureBgCategoryTablesExist() {
    // Creates bg_category and bg_category_import if not present (uses DB_PREFIX)
    $this->db->query("
        CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "bg_category` (
            `bg_cat_id` varchar(64) NOT NULL,
            `name` varchar(255) DEFAULT '',
            `parent_id` varchar(64) DEFAULT '0',
            `imported_at` datetime DEFAULT NULL,
            PRIMARY KEY (`bg_cat_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
    ");

    $this->db->query("
        CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "bg_category_import` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `bg_cat_id` varchar(64) NOT NULL,
            `raw_json` text,
            `page` int(11) DEFAULT 0,
            `imported_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY (`bg_cat_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
    ");
}

	// Add this method inside the ModelExtensionModuleBanggoodImport class
// Paste this inside the ModelExtensionModuleBanggoodImport class, replacing any other fetchProductList definition.
public function fetchProductList($cat_id, $page = 1, $page_size = 10, $filters = array()) {
    $result = array(
        'success' => false,
        'products' => array(),
        'page' => (int)$page,
        'page_total' => 0,
        'page_size' => (int)$page_size,
        'product_total' => 0,
        'errors' => array(),
        'raw' => null
    );

    $cat_id = trim((string)$cat_id);
    $page = max(1, (int)$page);
    // Back-compat: allow passing filters as 3rd arg
    if (is_array($page_size) && (empty($filters) || !is_array($filters))) {
        $filters = $page_size;
        $page_size = 10;
    }
    $page_size = max(1, (int)$page_size);
    if (!is_array($filters)) $filters = array();

    if ($cat_id === '') {
        $result['errors'][] = 'cat_id is empty';
        return $result;
    }

    $config = $this->getBanggoodConfig();
    $lang = isset($config['lang']) ? $config['lang'] : 'en';

    try {
        $params = array(
            // Banggood uses 'cat_id' or 'category' in some variants; use 'cat_id' per docs
            'cat_id'   => $cat_id,
            'page'     => $page,
            'pagesize' => $page_size,
            'lang'     => $lang
        );

        // Optional date filters (UTC strings per Banggood docs)
        $dateKeys = array('add_date_start', 'add_date_end', 'modify_date_start', 'modify_date_end');
        foreach ($dateKeys as $k) {
            if (!empty($filters[$k])) {
                $params[$k] = (string)$filters[$k];
            }
        }

        $resp = $this->apiRequest($config, 'product/getProductList', 'GET', $params);

        $result['raw'] = $resp;

        if (is_array($resp) && isset($resp['code']) && (int)$resp['code'] !== 0) {
            $msg = isset($resp['msg']) ? $resp['msg'] : (isset($resp['message']) ? $resp['message'] : 'API error');
            $result['errors'][] = "Banggood API error: code=" . (int)$resp['code'] . " msg=" . $msg;
            return $result;
        }

        $products = array();
        if (is_array($resp)) {
            // common locations
            if (!empty($resp['product_list']) && is_array($resp['product_list'])) {
                $products = $resp['product_list'];
            } elseif (!empty($resp['data']) && is_array($resp['data'])) {
                if (!empty($resp['data']['product_list']) && is_array($resp['data']['product_list'])) {
                    $products = $resp['data']['product_list'];
                } elseif (!empty($resp['data']['list']) && is_array($resp['data']['list'])) {
                    $products = $resp['data']['list'];
                } elseif (!empty($resp['data']['products']) && is_array($resp['data']['products'])) {
                    $products = $resp['data']['products'];
                }
            } elseif (!empty($resp['data']['products']) && is_array($resp['data']['products'])) {
                $products = $resp['data']['products'];
            }
        }

        // fallback: response itself may be numeric-indexed list
        if (empty($products) && is_array($resp) && !$this->isAssociative($resp)) {
            $first = reset($resp);
            if (is_array($first) && (isset($first['product_id']) || isset($first['product_name']) || isset($first['img']))) {
                $products = $resp;
            }
        }

        $normalized = array();
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $product_id = isset($p['product_id']) ? (string)$p['product_id'] : (isset($p['productId']) ? (string)$p['productId'] : '');
            if ($product_id === '') continue;
            $normalized[] = array(
                'product_id'   => $product_id,
                'cat_id'       => isset($p['cat_id']) ? (string)$p['cat_id'] : (isset($p['catId']) ? (string)$p['catId'] : $cat_id),
                'product_name' => isset($p['product_name']) ? $p['product_name'] : (isset($p['productName']) ? $p['productName'] : ''),
                'img'          => isset($p['img']) ? $p['img'] : (isset($p['image']) ? $p['image'] : ''),
                'meta_desc'    => isset($p['meta_desc']) ? $p['meta_desc'] : (isset($p['meta_description']) ? $p['meta_description'] : ''),
                'add_date'     => isset($p['add_date']) ? $p['add_date'] : (isset($p['addDate']) ? $p['addDate'] : ''),
                'modify_date'  => isset($p['modify_date']) ? $p['modify_date'] : (isset($p['modifyDate']) ? $p['modifyDate'] : '')
            );
        }

        $result['products'] = $normalized;

        if (is_array($resp)) {
            if (isset($resp['page'])) $result['page'] = (int)$resp['page'];
            if (isset($resp['page_total'])) $result['page_total'] = (int)$resp['page_total'];
            if (isset($resp['page_size'])) $result['page_size'] = (int)$resp['page_size'];
            if (isset($resp['product_total'])) $result['product_total'] = (int)$resp['product_total'];

            if (isset($resp['data']) && is_array($resp['data'])) {
                if (isset($resp['data']['page'])) $result['page'] = (int)$resp['data']['page'];
                if (isset($resp['data']['page_total'])) $result['page_total'] = (int)$resp['data']['page_total'];
                if (isset($resp['data']['page_size'])) $result['page_size'] = (int)$resp['data']['page_size'];
                if (isset($resp['data']['product_total'])) $result['product_total'] = (int)$resp['data']['product_total'];
            }
        }

        $result['success'] = true;
        return $result;

    } catch (\Exception $e) {
        $result['errors'][] = 'Exception: ' . $e->getMessage();
        return $result;
    }
}

    /**
     * Fetch a list of products updated within the last N minutes.
     *
     * Banggood API: product/getProductUpdateList
     * Docs: minutes (Int, max 21600), page (Int), lang (String)
     *
     * Returns:
     * - success (bool)
     * - updates (array of [product_id, state, modify_date])
     * - page, page_total, page_size, product_total
     * - errors (array)
     * - raw (mixed)
     */
    public function fetchProductUpdateList($minutes = 30, $page = 1) {
        $result = array(
            'success' => false,
            'updates' => array(),
            'page' => (int)$page,
            'page_total' => 0,
            'page_size' => 0,
            'product_total' => 0,
            'errors' => array(),
            'raw' => null
        );

        $minutes = (int)$minutes;
        if ($minutes <= 0) $minutes = 30;
        if ($minutes > 21600) $minutes = 21600;
        $page = max(1, (int)$page);

        $config = $this->getBanggoodConfig();
        $lang = isset($config['lang']) ? $config['lang'] : 'en';

        try {
            $params = array(
                'minutes' => $minutes,
                'page' => $page,
                'lang' => $lang
            );

            $resp = $this->apiRequest($config, 'product/getProductUpdateList', 'GET', $params);
            $result['raw'] = $resp;

            $list = array();
            if (is_array($resp)) {
                if (!empty($resp['update_product_list']) && is_array($resp['update_product_list'])) {
                    $list = $resp['update_product_list'];
                } elseif (!empty($resp['product_list']) && is_array($resp['product_list'])) {
                    // some examples use product_list for this API
                    $list = $resp['product_list'];
                } elseif (!empty($resp['data']) && is_array($resp['data'])) {
                    if (!empty($resp['data']['update_product_list']) && is_array($resp['data']['update_product_list'])) {
                        $list = $resp['data']['update_product_list'];
                    } elseif (!empty($resp['data']['product_list']) && is_array($resp['data']['product_list'])) {
                        $list = $resp['data']['product_list'];
                    } elseif (!empty($resp['data']['list']) && is_array($resp['data']['list'])) {
                        $list = $resp['data']['list'];
                    }
                }
            }

            $updates = array();
            foreach ($list as $u) {
                if (!is_array($u)) continue;
                $product_id = isset($u['product_id']) ? (string)$u['product_id'] : (isset($u['productId']) ? (string)$u['productId'] : '');
                if ($product_id === '') continue;
                $updates[] = array(
                    'product_id' => $product_id,
                    'state' => isset($u['state']) ? (int)$u['state'] : (isset($u['status']) ? (int)$u['status'] : 0),
                    'modify_date' => isset($u['modify_date']) ? (string)$u['modify_date'] : (isset($u['modifyDate']) ? (string)$u['modifyDate'] : '')
                );
            }

            $result['updates'] = $updates;

            if (is_array($resp)) {
                if (isset($resp['page'])) $result['page'] = (int)$resp['page'];
                if (isset($resp['page_total'])) $result['page_total'] = (int)$resp['page_total'];
                if (isset($resp['page_size'])) $result['page_size'] = (int)$resp['page_size'];
                if (isset($resp['product_total'])) $result['product_total'] = (int)$resp['product_total'];

                if (isset($resp['data']) && is_array($resp['data'])) {
                    if (isset($resp['data']['page'])) $result['page'] = (int)$resp['data']['page'];
                    if (isset($resp['data']['page_total'])) $result['page_total'] = (int)$resp['data']['page_total'];
                    if (isset($resp['data']['page_size'])) $result['page_size'] = (int)$resp['data']['page_size'];
                    if (isset($resp['data']['product_total'])) $result['product_total'] = (int)$resp['data']['product_total'];
                }
            }

            $result['success'] = true;
            return $result;
        } catch (\Exception $e) {
            $result['errors'][] = 'Exception: ' . $e->getMessage();
            return $result;
        }
    }

	// Add these methods inside the ModelExtensionModuleBanggoodImport class.

    /**
     * Ensure the persistent fetched-products table exists.
     */
    protected function ensureFetchedProductsTableExists() {
        $tbl = $this->getFetchedProductsTableName();
        // IMPORTANT: do not escape table identifiers inside backticks
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . $tbl . "` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `bg_product_id` varchar(64) NOT NULL,
            `cat_id` varchar(64) DEFAULT NULL,
            `name` varchar(255) DEFAULT NULL,
            `img` text,
            `meta_desc` text,
            `raw_json` longtext,
            `fetched_at` datetime DEFAULT NULL,
            `status` varchar(32) DEFAULT 'pending',
            `last_error` text DEFAULT NULL,
            `imported_at` datetime DEFAULT NULL,
            `updated_at` datetime DEFAULT NULL,
            `attempts` int(11) DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_bg_product` (`bg_product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

        // If table already exists but is missing new columns, add them (safe, non-destructive).
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM `" . $tbl . "`")->rows;
            $have = array();
            foreach ($cols as $c) $have[strtolower($c['Field'])] = true;

            // Some installs created a column named "updated at" (with space). Support both.
            $has_updated_at = isset($have['updated_at']) || isset($have['updated at']);
            if (!$has_updated_at) {
                $this->db->query("ALTER TABLE `" . $tbl . "` ADD COLUMN `updated_at` datetime DEFAULT NULL");
            }
            if (!isset($have['imported_at'])) {
                $this->db->query("ALTER TABLE `" . $tbl . "` ADD COLUMN `imported_at` datetime DEFAULT NULL");
            }
        } catch (\Throwable $e) {
            // ignore; table may not allow alter in some environments
        }
    }

    /**
     * Return the name of the "updated at" column if it exists (supports "updated_at" or "updated at").
     */
    protected function getFetchedProductsUpdatedAtColumnName() {
        $tbl = $this->getFetchedProductsTableName();
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM `" . $tbl . "`")->rows;
            foreach ($cols as $c) {
                $f = (string)$c['Field'];
                if (strcasecmp($f, 'updated_at') === 0) return 'updated_at';
                if (strcasecmp($f, 'updated at') === 0) return 'updated at';
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /**
     * Return the name of the "imported_at" column if it exists.
     */
    protected function getFetchedProductsImportedAtColumnName() {
        $tbl = $this->getFetchedProductsTableName();
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM `" . $tbl . "`")->rows;
            foreach ($cols as $c) {
                $f = (string)$c['Field'];
                if (strcasecmp($f, 'imported_at') === 0) return 'imported_at';
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /**
     * Prefer oc_bg_fetched_products if it exists (matches phpMyAdmin expectation),
     * otherwise use DB_PREFIX . bg_fetched_products.
     */
    protected function getFetchedProductsTableName() {
        $preferred = 'oc_bg_fetched_products';
        try {
            $q = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($preferred) . "'");
            if ($q && $q->num_rows) return $preferred;
        } catch (\Throwable $e) {}
        return DB_PREFIX . "bg_fetched_products";
    }

    /**
     * Persist an array of normalized products into the fetched-products table.
     * Each product array should contain at least 'product_id' and optionally other fields.
     * Returns number of rows processed (attempted inserts/updates).
     */
    public function saveFetchedProducts(array $products) {
        if (empty($products)) return 0;
        $this->ensureFetchedProductsTableExists();
        $tbl = $this->getFetchedProductsTableName();
        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($products as $p) {
            if (!is_array($p) || empty($p['product_id'])) continue;
            $bgid = $this->db->escape((string)$p['product_id']);
            $cat = isset($p['cat_id']) ? $this->db->escape((string)$p['cat_id']) : '';
            $name = isset($p['product_name']) ? $this->db->escape((string)$p['product_name']) : (isset($p['name']) ? $this->db->escape((string)$p['name']) : '');
            $img = isset($p['img']) ? $this->db->escape((string)$p['img']) : '';
            $meta = isset($p['meta_desc']) ? $this->db->escape((string)$p['meta_desc']) : '';
            // Use JSON_UNESCAPED_UNICODE to keep readable stored JSON; escape for DB
            $rawJson = $this->db->escape(json_encode($p, JSON_UNESCAPED_UNICODE));

            // Insert or update existing row. Do not change status if existing status = 'imported'
            // ON DUPLICATE KEY UPDATE will overwrite metadata and fetched_at
            $sql = "INSERT INTO `" . $tbl . "`
                (`bg_product_id`,`cat_id`,`name`,`img`,`meta_desc`,`raw_json`,`fetched_at`)
                VALUES ('" . $bgid . "','" . $cat . "','" . $name . "','" . $img . "','" . $meta . "','" . $rawJson . "','" . $this->db->escape($now) . "')
                ON DUPLICATE KEY UPDATE
                  `cat_id` = VALUES(`cat_id`),
                  `name` = VALUES(`name`),
                  `img` = VALUES(`img`),
                  `meta_desc` = VALUES(`meta_desc`),
                  `raw_json` = VALUES(`raw_json`),
                  `fetched_at` = VALUES(`fetched_at`)";
            $this->db->query($sql);
            $count++;
        }

        return $count;
    }

    /**
     * Fetch pending rows and atomically mark them as 'processing'.
     * Returns an array of rows (associative arrays) up to $limit.
     */
    public function fetchPendingForProcessing($limit = 20) {
        $this->ensureFetchedProductsTableExists();
        $tbl = $this->getFetchedProductsTableName();

        // Select rows needing processing:
        // - pending
        // - updated where updated_at is NULL (if column exists), otherwise include updated too.
        $updatedCol = $this->getFetchedProductsUpdatedAtColumnName();
        $where = "`status` = 'pending'";
        if ($updatedCol) {
            $where .= " OR (`status` = 'updated' AND `" . $updatedCol . "` IS NULL)";
        } else {
            $where .= " OR `status` = 'updated'";
        }

        $qr = $this->db->query("SELECT * FROM `" . $tbl . "` WHERE (" . $where . ") ORDER BY `fetched_at` ASC, `id` ASC LIMIT " . (int)$limit);
        $rows = $qr->rows;

        if (!empty($rows)) {
            $ids = array();
            foreach ($rows as $r) $ids[] = (int)$r['id'];
            // Mark selected rows as processing and increment attempts
            $this->db->query("UPDATE `" . $tbl . "` SET `status` = 'processing', `attempts` = `attempts` + 1 WHERE `id` IN (" . implode(',', $ids) . ")");
        }

        return $rows;
    }

    /**
     * Mark a persisted fetched product as successfully imported.
     */
    public function markFetchedProductImported($bg_product_id) {
        if (empty($bg_product_id)) return;
        $this->ensureFetchedProductsTableExists();
        $tbl = $this->getFetchedProductsTableName();
        $now = date('Y-m-d H:i:s');

        // If this row was queued as an "updated" item, keep status=updated and stamp updated_at.
        $cur = null;
        try {
            $q = $this->db->query("SELECT `status` FROM `" . $tbl . "` WHERE `bg_product_id` = '" . $this->db->escape((string)$bg_product_id) . "' LIMIT 1");
            if ($q && $q->num_rows) $cur = isset($q->row['status']) ? (string)$q->row['status'] : null;
        } catch (\Throwable $e) {}

        $updatedCol = $this->getFetchedProductsUpdatedAtColumnName();
        $importedCol = $this->getFetchedProductsImportedAtColumnName();

        if ($cur === 'updated') {
            $set = array("`status` = 'updated'", "`last_error` = NULL");
            if ($updatedCol) $set[] = "`" . $updatedCol . "` = '" . $this->db->escape($now) . "'";
            $this->db->query("UPDATE `" . $tbl . "` SET " . implode(', ', $set) . " WHERE `bg_product_id` = '" . $this->db->escape((string)$bg_product_id) . "'");
        } else {
            $set = array("`status` = 'imported'", "`last_error` = NULL");
            if ($importedCol) $set[] = "`" . $importedCol . "` = '" . $this->db->escape($now) . "'";
            $this->db->query("UPDATE `" . $tbl . "` SET " . implode(', ', $set) . " WHERE `bg_product_id` = '" . $this->db->escape((string)$bg_product_id) . "'");
        }
    }

    /**
     * Mark a persisted fetched product as error with optional message.
     */
    public function markFetchedProductError($bg_product_id, $message = '') {
        if (empty($bg_product_id)) return;
        $this->ensureFetchedProductsTableExists();
        $tbl = $this->getFetchedProductsTableName();
        $this->db->query("UPDATE `" . $tbl . "` SET `status` = 'error', `last_error` = '" . $this->db->escape((string)$message) . "' WHERE `bg_product_id` = '" . $this->db->escape((string)$bg_product_id) . "'");
    }

    /**
     * Return simple stats about fetched products (pending/processing/imported/error/total).
     */
    public function getFetchedProductsStats() {
        $this->ensureFetchedProductsTableExists();
        $tbl = $this->getFetchedProductsTableName();
        $row = $this->db->query("SELECT 
            SUM(`status` = 'pending') AS pending,
            SUM(`status` = 'processing') AS processing,
            SUM(`status` = 'imported') AS imported,
            SUM(`status` = 'error') AS error,
            COUNT(*) AS total
            FROM `" . $tbl . "`")->row;

        // Ensure integer values
        return array(
            'pending' => isset($row['pending']) ? (int)$row['pending'] : 0,
            'processing' => isset($row['processing']) ? (int)$row['processing'] : 0,
            'imported' => isset($row['imported']) ? (int)$row['imported'] : 0,
            'error' => isset($row['error']) ? (int)$row['error'] : 0,
            'total' => isset($row['total']) ? (int)$row['total'] : 0
        );
    }

    /**
     * Optional convenience: fetch recent persisted fetched products for display.
     */
    public function fetchRecentFetchedProducts($limit = 50) {
        $this->ensureFetchedProductsTableExists();
        $tbl = $this->getFetchedProductsTableName();
        $qr = $this->db->query("SELECT * FROM `" . $tbl . "` ORDER BY `fetched_at` DESC, `id` DESC LIMIT " . (int)$limit);
        return $qr->rows;
    }

    /**
     * Import a product by Banggood product ID.
     * Uses existing fetchProductDetail(), normalizeProduct(), and upsertProduct() helpers already in this model.
     * Returns array('result' => 'created'|'updated'|'skip') or throws on fatal.
     */
    public function importProductById($product_id) {
        if (empty($product_id)) throw new Exception('Empty product id');

        // Controlled mapping writes: enable if admin has allowed it (same behavior as other import methods)
        $allow_map_writes = (bool)$this->config->get('module_banggood_import_allow_map_writes');
        if ($allow_map_writes) {
            try { $this->db->query("SET @bg_allow_write = 1"); } catch (Exception $e) {}
        }

        try {
            $this->load->model('catalog/product');
            $config = $this->getBanggoodConfig();

            // fetch raw product detail from Banggood API
            $raw = $this->fetchProductDetail($config, $product_id);
            if (empty($raw) || !is_array($raw)) {
                throw new Exception('No product data returned from API for id ' . $product_id);
            }

            // normalize and upsert
            $normalized = $this->normalizeProduct($raw, $config);
            $result = $this->upsertProduct($normalized);

            // IMPORTANT: Ensure GetStocks is applied on (re)import so that:
            // - product_option_value quantities are updated
            // - oc_product_variant rows are (re)generated and updated
            // - oc_product_variant.stock_status_token is filled from GetStocks stock_msg
            try {
                $oc_product_id = (int)$this->findExistingProductByBanggoodId((string)$normalized['bg_id']);
                if ($oc_product_id > 0) {
                    // Always run stock/variant upsert even if upsertProduct() returned 'skip'
                    $stocks_diag = $this->applyStocksToProduct((string)$normalized['bg_id'], $oc_product_id, $this->getBanggoodConfig());

                    // Diagnostics: confirm whether any stock_msg values exist and whether variants got tokens
                    $sample = array();
                    try {
                        $stocks = $this->getStocksForProduct($this->getBanggoodConfig(), (string)$normalized['bg_id']);
                        if (is_array($stocks)) {
                            $i = 0;
                            foreach ($stocks as $s) {
                                if ($i++ >= 5) break;
                                $sample[] = array(
                                    'warehouse' => isset($s['warehouse']) ? $s['warehouse'] : null,
                                    'poa_id' => isset($s['poa_id']) ? $s['poa_id'] : null,
                                    'stock' => isset($s['stock']) ? $s['stock'] : null,
                                    'stock_msg' => isset($s['stock_msg']) ? $s['stock_msg'] : null
                                );
                            }
                        }
                    } catch (Exception $e) {}

                    $qcnt = $this->db->query(
                        "SELECT
                            SUM(CASE WHEN stock_status_token IS NOT NULL AND stock_status_token <> '' THEN 1 ELSE 0 END) AS with_token,
                            COUNT(*) AS total
                         FROM `" . DB_PREFIX . "product_variant`
                         WHERE product_id = " . (int)$oc_product_id
                    );
                    $with_token = isset($qcnt->row['with_token']) ? (int)$qcnt->row['with_token'] : 0;
                    $total = isset($qcnt->row['total']) ? (int)$qcnt->row['total'] : 0;

                    $this->writeDebugLog((string)$normalized['bg_id'], array(
                        'notice' => 'importProductById_post_applyStocksToProduct',
                        'product_id' => $oc_product_id,
                        'result' => $result,
                        'stocks_sample' => $sample,
                        'variants_with_stock_status_token' => $with_token,
                        'variants_total' => $total,
                        'applyStocksToProduct_diagnostics_keys' => is_array($stocks_diag) ? array_keys($stocks_diag) : null
                    ));

                    // If this product is present in bg_fetched_products queue, mark it as imported/updated now.
                    // This makes status accurate even when imports happen outside fetchProductsChunk (manual import, update list, etc.).
                    try { $this->markFetchedProductImported((string)$product_id); } catch (\Throwable $e) {}
                }
            } catch (Exception $e) {
                // don't fail the import for stock-status backfill issues
                try { $this->writeDebugLog((string)$normalized['bg_id'], array('warning' => 'importProductById_post_applyStocksToProduct_failed', 'error' => $e->getMessage())); } catch (Exception $x) {}
            }

            return array('result' => $result);
        } finally {
            if ($allow_map_writes) {
                try { $this->db->query("SET @bg_allow_write = NULL"); } catch (Exception $e) {}
            }
        }
    }
	
/**
 * Import Banggood categories into bg tables (does not modify OpenCart categories).
 * Returns array with summary info.
 */
// Replace the existing importBgCategoriesToBgTables(...) method with this.
// Replace the existing importBgCategoriesToBgTables(...) method in admin/model/extension/module/banggood_import.php with this full implementation.
// Supports an optional $deleteMissing boolean flag which, when true, will remove rows from the target bg_category table
// that were NOT present in the current import (uses a temporary table + chunked inserts for safety/efficiency).
public function importBgCategoriesToBgTables($force = false, $deleteMissing = false) {
    // Ensure canonical tables exist
    try { $this->ensureBgCategoryTablesExist(); } catch (Exception $e) { /* continue, will error later if needed */ }

    // Get config and language
    $config = array();
    try { $config = $this->getBanggoodConfig(); } catch (Exception $e) { $config = array(); }
    $lang = isset($config['lang']) ? $config['lang'] : 'en';

    $targetTable = DB_PREFIX . 'bg_category';
    $importTable = DB_PREFIX . 'bg_category_import';

    // Helper to get columns for a table
    $getCols = function($table) {
        $cols = array();
        try {
            $qr = $this->db->query("SHOW COLUMNS FROM `" . $this->db->escape($table) . "`");
            if ($qr && $qr->rows) {
                foreach ($qr->rows as $r) $cols[] = $r['Field'];
            }
        } catch (\Exception $e) {
            // ignore
        }
        return $cols;
    };

    $targetCols = $getCols($targetTable);
    $importCols = $getCols($importTable);

    $useBgcSchema = in_array('bg_cat_id', $targetCols);
    $useLegacySchema = in_array('cat_id', $targetCols);

    // If target schema unknown, try to create preferred target table
    if (!$useBgcSchema && !$useLegacySchema) {
        try { $this->ensureTargetTableExists($targetTable); } catch (\Exception $e) {}
        $targetCols = $getCols($targetTable);
        $useBgcSchema = in_array('bg_cat_id', $targetCols);
        $useLegacySchema = in_array('cat_id', $targetCols);
    }

    $page = 1;
    $imported = 0;
    $updated = 0;
    $errors = array();
    $seenIds = array(); // collect bg_cat_id strings for sweep

    while (true) {
        try {
            $response = $this->apiRequest($config, 'category/getCategoryList', 'GET', array('page' => $page, 'lang' => $lang));
        } catch (\Exception $e) {
            $errors[] = "API request failed on page {$page}: " . $e->getMessage();
            break;
        }

        $cats = array();
        if (is_array($response)) {
            if (isset($response['cat_list']) && is_array($response['cat_list'])) $cats = $response['cat_list'];
            elseif (isset($response['data']) && is_array($response['data'])) {
                if (isset($response['data']['cat_list']) && is_array($response['data']['cat_list'])) $cats = $response['data']['cat_list'];
                elseif ($this->looksLikeCategoryArray($response['data'])) $cats = $response['data'];
            } elseif ($this->looksLikeCategoryArray($response)) {
                $cats = $response;
            }
        }

        if (empty($cats)) {
            break;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($cats as $c) {
            $bg_cat_id = isset($c['cat_id']) ? (string)$c['cat_id'] : (isset($c['catId']) ? (string)$c['catId'] : '');
            if ($bg_cat_id === '') continue;

            $name = isset($c['cat_name']) ? (string)$c['cat_name'] : (isset($c['name']) ? (string)$c['name'] : '');
            $parent = isset($c['parent_id']) ? (string)$c['parent_id'] : (isset($c['parentId']) ? (string)$c['parentId'] : '0');

            // record seen id for optional sweep later
            $seenIds[] = (string)$bg_cat_id;

            try {
                if ($useBgcSchema) {
                    // Upsert by bg_cat_id
                    $exists = $this->db->query("SELECT bg_cat_id FROM `" . $this->db->escape($targetTable) . "` WHERE bg_cat_id = '" . $this->db->escape($bg_cat_id) . "' LIMIT 1");
                    if ($exists && $exists->num_rows) {
                        $parts = array();
                        if (in_array('name', $targetCols)) $parts[] = "`name` = '" . $this->db->escape($name) . "'";
                        if (in_array('parent_id', $targetCols)) $parts[] = "`parent_id` = '" . $this->db->escape($parent) . "'";
                        if (in_array('imported_at', $targetCols)) $parts[] = "`imported_at` = '" . $this->db->escape($now) . "'";
                        if (!empty($parts)) {
                            $this->db->query("UPDATE `" . $this->db->escape($targetTable) . "` SET " . implode(', ', $parts) . " WHERE bg_cat_id = '" . $this->db->escape($bg_cat_id) . "' LIMIT 1");
                            $updated++;
                        }
                    } else {
                        $cols = $vals = array();
                        if (in_array('bg_cat_id', $targetCols)) { $cols[] = '`bg_cat_id`'; $vals[] = "'" . $this->db->escape($bg_cat_id) . "'"; }
                        if (in_array('name', $targetCols)) { $cols[] = '`name`'; $vals[] = "'" . $this->db->escape($name) . "'"; }
                        if (in_array('parent_id', $targetCols)) { $cols[] = '`parent_id`'; $vals[] = "'" . $this->db->escape($parent) . "'"; }
                        if (in_array('imported_at', $targetCols)) { $cols[] = '`imported_at`'; $vals[] = "'" . $this->db->escape($now) . "'"; }
                        if (!empty($cols)) {
                            $this->db->query("INSERT INTO `" . $this->db->escape($targetTable) . "` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")");
                            $imported++;
                        } else {
                            $errors[] = "Cannot insert into {$targetTable}: unknown insertable columns for bg schema.";
                        }
                    }
                } elseif ($useLegacySchema) {
                    // Legacy upsert by cat_id
                    $cat_id = $bg_cat_id;
                    $exists = $this->db->query("SELECT cat_id FROM `" . $this->db->escape($targetTable) . "` WHERE cat_id = '" . $this->db->escape($cat_id) . "' LIMIT 1");
                    if ($exists && $exists->num_rows) {
                        $parts = array();
                        if (in_array('name', $targetCols)) $parts[] = "`name` = '" . $this->db->escape($name) . "'";
                        if (in_array('parent_cat_id', $targetCols)) $parts[] = "`parent_cat_id` = '" . $this->db->escape($parent) . "'";
                        if (!empty($parts)) {
                            $this->db->query("UPDATE `" . $this->db->escape($targetTable) . "` SET " . implode(', ', $parts) . " WHERE cat_id = '" . $this->db->escape($cat_id) . "' LIMIT 1");
                            $updated++;
                        }
                    } else {
                        $cols = $vals = array();
                        if (in_array('cat_id', $targetCols)) { $cols[] = '`cat_id`'; $vals[] = "'" . $this->db->escape($cat_id) . "'"; }
                        if (in_array('parent_cat_id', $targetCols)) { $cols[] = '`parent_cat_id`'; $vals[] = "'" . $this->db->escape($parent) . "'"; }
                        if (in_array('name', $targetCols)) { $cols[] = '`name`'; $vals[] = "'" . $this->db->escape($name) . "'"; }
                        if (!empty($cols)) {
                            $this->db->query("INSERT INTO `" . $this->db->escape($targetTable) . "` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")");
                            $imported++;
                        } else {
                            $errors[] = "Cannot insert into {$targetTable}: unknown insertable columns for legacy schema.";
                        }
                    }
                } else {
                    $errors[] = "Unknown bg_category schema for table {$targetTable}.";
                }
            } catch (\Exception $e) {
                $errors[] = "DB write error for bg_cat_id={$bg_cat_id}: " . $e->getMessage();
            }

            // Record raw import row into import table if appropriate
            try {
                if (!empty($importCols)) {
                    $iCols = $iVals = array();
                    if (in_array('bg_cat_id', $importCols)) { $iCols[] = '`bg_cat_id`'; $iVals[] = "'" . $this->db->escape($bg_cat_id) . "'"; }
                    if (in_array('raw_json', $importCols)) { $iCols[] = '`raw_json`'; $iVals[] = "'" . $this->db->escape(json_encode($c)) . "'"; }
                    elseif (in_array('raw_data', $importCols)) { $iCols[] = '`raw_data`'; $iVals[] = "'" . $this->db->escape(json_encode($c)) . "'"; }
                    if (in_array('page', $importCols)) { $iCols[] = '`page`'; $iVals[] = (int)$page; }
                    if (in_array('imported_at', $importCols)) { $iCols[] = '`imported_at`'; $iVals[] = "'" . $this->db->escape($now) . "'"; }

                    if (!empty($iCols) && !empty($iVals)) {
                        $this->db->query("INSERT INTO `" . $this->db->escape($importTable) . "` (" . implode(',', $iCols) . ") VALUES (" . implode(',', $iVals) . ")");
                    }
                }
            } catch (\Exception $e) {
                $errors[] = "DB import-row write error for bg_cat_id={$bg_cat_id}: " . $e->getMessage();
            }
        } // end foreach cats

        // Stop if response contains page_total and we've reached it
        if (isset($response['page_total']) && is_numeric($response['page_total']) && $page >= (int)$response['page_total']) {
            break;
        }

        $page++;
        if ($page > 2000) { $errors[] = "Page loop exceeded safety limit"; break; }
    } // end while pages

    // Optional post-import sweep: delete target rows not seen in this run
    if ($deleteMissing) {
        try {
            $seenIds = array_values(array_unique($seenIds));
            if (!empty($seenIds)) {
                // Determine id column name to use
                if ($useBgcSchema) $idCol = 'bg_cat_id';
                elseif ($useLegacySchema) $idCol = 'cat_id';
                else $idCol = null;

                if ($idCol) {
                    $tmpTable = DB_PREFIX . 'bg_tmp_import_ids';

                    // Drop/create temp table
                    $this->db->query("DROP TABLE IF EXISTS `" . $this->db->escape($tmpTable) . "`");
                    $this->db->query("CREATE TABLE `" . $this->db->escape($tmpTable) . "` (`" . $this->db->escape($idCol) . "` varchar(64) NOT NULL, INDEX(`" . $this->db->escape($idCol) . "`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    // Insert ids in chunks
                    $chunkSize = 1000;
                    $chunks = array_chunk($seenIds, $chunkSize);
                    foreach ($chunks as $chunk) {
                        $vals = array();
                        foreach ($chunk as $v) {
                            $vals[] = "('" . $this->db->escape((string)$v) . "')";
                        }
                        if (!empty($vals)) {
                            $this->db->query("INSERT INTO `" . $this->db->escape($tmpTable) . "` (`" . $this->db->escape($idCol) . "`) VALUES " . implode(',', $vals));
                        }
                    }

                    // Delete rows not present in tmp table (LEFT JOIN)
                    $this->db->query("DELETE t FROM `" . $this->db->escape($targetTable) . "` t LEFT JOIN `" . $this->db->escape($tmpTable) . "` ti ON ti.`" . $this->db->escape($idCol) . "` = t.`" . $this->db->escape($idCol) . "` WHERE ti.`" . $this->db->escape($idCol) . "` IS NULL");

                    // Drop tmp
                    $this->db->query("DROP TABLE IF EXISTS `" . $this->db->escape($tmpTable) . "`");
                } else {
                    $errors[] = "Post-import sweep cannot run: unknown id column on target table.";
                }
            } else {
                // nothing imported - skip sweep
            }
        } catch (\Exception $e) {
            $errors[] = "Post-import sweep error: " . $e->getMessage();
        }
    }

    // Return summary (keep existing keys for compatibility)
    return array(
        'inserted' => (int)$imported,
        'updated'  => (int)$updated,
        'errors'   => $errors,
        'swept'    => $deleteMissing ? true : false,
        'html'     => (method_exists($this, 'getBgCategoriesHtml') ? $this->getBgCategoriesHtml() : '')
    );
}

/**
 * Lightweight raw GET helper used by import (fallback if you don't use existing wrapper)
 */
	// Replace or add this method inside ModelExtensionModuleBanggoodImport

	
protected function apiRequestRawSimple($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'bg_category_import/1.0'
    ));
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return null;
    return $body;
}

    /* -------------------------
       Helper: find candidate category array in response
       ------------------------- */
    protected function findCandidateInResponse($resp) {
        // Return category-like array if found, otherwise null
        if (!is_array($resp) && !is_string($resp)) return null;

        if (is_array($resp)) {
            if ($this->looksLikeCategoryArray($resp)) return $resp;

            $keys = array('data','categories','category','list','result','rows','items','cat_list','catList','category_list','categoryList');
            foreach ($keys as $k) {
                if (isset($resp[$k]) && is_array($resp[$k]) && $this->looksLikeCategoryArray($resp[$k])) {
                    return $resp[$k];
                }
            }

            // breadth-first search
            $queue = array($resp);
            $visited = 0;
            while (!empty($queue) && $visited < 200) {
                $part = array_shift($queue);
                if (is_array($part) && $this->looksLikeCategoryArray($part)) return $part;
                if (is_array($part)) {
                    foreach ($part as $v) {
                        if (is_array($v)) $queue[] = $v;
                    }
                }
                $visited++;
            }
        }

        if (is_string($resp)) {
            $decoded = @json_decode($resp, true);
            if (is_array($decoded) && $this->looksLikeCategoryArray($decoded)) return $decoded;
            if (preg_match('/(\[\\s*\\{[\\s\\S]{20,}\\}\\s*\\])/U', $resp, $m)) {
                $try = @json_decode($m[1], true);
                if (is_array($try) && $this->looksLikeCategoryArray($try)) return $try;
            }
        }

        return null;
    }

    // Add this method inside the ModelExtensionModuleBanggoodImport class
    protected function looksLikeCategoryArray($arr) {
        // Return true when $arr appears to be a category list or single category node.
        if (!is_array($arr)) {
            return false;
        }

        // Common identifying keys for a category object or list item
        $idKeys = array('cat_id', 'category_id', 'id', 'cate_id', 'bg_cat_id');
        $nameKeys = array('cat_name', 'name', 'title', 'label');

        // If associative and contains an id or name key -> looks like category node
        $assoc = array_keys($arr) !== range(0, count($arr) - 1);
        if ($assoc) {
            foreach ($idKeys as $k) if (isset($arr[$k]) && $arr[$k] !== '') return true;
            foreach ($nameKeys as $k) if (isset($arr[$k]) && $arr[$k] !== '') return true;
            // also if it has a 'children' key that's an array
            if (isset($arr['children']) && is_array($arr['children']) && !empty($arr['children'])) return true;
            return false;
        }

        // If numeric-indexed list, check the first element for category-like shape
        if (count($arr) === 0) return false;
        $first = reset($arr);
        if (!is_array($first)) return false;
        foreach ($idKeys as $k) if (isset($first[$k]) && $first[$k] !== '') return true;
        foreach ($nameKeys as $k) if (isset($first[$k]) && $first[$k] !== '') return true;
        // also consider arrays where each item contains 'children' or 'list'
        if (isset($first['children']) && is_array($first['children'])) return true;
        if (isset($first['list']) && is_array($first['list'])) return true;

        return false;
    }
    /* -------------------------
       Helper: fetch children for a given parent category id
       ------------------------- */
    protected function fetchChildrenForParent($config, $parent_bg_cat_id, $lang_code) {
        $out = array();
        if (empty($parent_bg_cat_id)) return $out;

        try {
            $token = $this->getAccessToken($config);
        } catch (Exception $e) {
            return $out;
        }

        $child_tasks = array('category/getChildren','category/getSubList','category/getCategoryChild','category/getList','product/getCategoryList','category/getCategoryList','openapi/category/list','openapi/v1/category/list','api/v1/category/list','v1/category/list');
        $paramKeys = array('parent_id','parent','category','parentCategoryId','cate_id','pid','parentId');

        foreach ($child_tasks as $task) {
            foreach ($paramKeys as $pk) {
                try {
                    $params = array('lang' => $lang_code, 'access_token' => $token);
                    $params[$pk] = $parent_bg_cat_id;
                    $resp = $this->apiRequestRaw($config, $task, 'GET', $params);
                    $candidate = $this->findCandidateInResponse($resp);
                    if (is_array($candidate) && !empty($candidate)) {
                        // If candidate is associative with 'children' key, return that
                        if ($this->isAssociative($candidate) && isset($candidate['children']) && is_array($candidate['children'])) {
                            return $candidate['children'];
                        }
                        return $candidate;
                    }
                } catch (Exception $e) {
                    // try next
                    continue;
                }
            }
        }

        return $out;
    }

    /* -------------------------
       Helper: augment nodes recursively by fetching children
       ------------------------- */
    protected function augmentNodesWithChildren(&$nodes, $config, $lang) {
        if (!is_array($nodes)) return;
        foreach ($nodes as &$node) {
            $bg_cat_id = '';
            if (isset($node['cat_id'])) $bg_cat_id = (string)$node['cat_id'];
            elseif (isset($node['category_id'])) $bg_cat_id = (string)$node['category_id'];
            elseif (isset($node['id'])) $bg_cat_id = (string)$node['id'];
            elseif (isset($node['cate_id'])) $bg_cat_id = (string)$node['cate_id'];

            if ($bg_cat_id !== '') {
                $children = $this->fetchChildrenForParent($config, $bg_cat_id, $lang);
                if (!empty($children) && is_array($children)) {
                    $node['children'] = $children;
                    // recurse
                    $this->augmentNodesWithChildren($node['children'], $config, $lang);
                }
            }
        }
        unset($node);
    }

    /**
     * Compute and persist an aggregated banggood_status for a given option_value_id.
     * Prefers the most frequent non-empty product_stock_msg, else sums stock and uses AVAILABLE|SOLD_OUT.
     */
    protected function syncOptionValueBanggoodData($option_value_id) {
        $option_value_id = (int)$option_value_id;
        if (!$option_value_id) return;

        // Try to pick the most frequent non-empty product_stock_msg
        $query = $this->db->query(
            "SELECT w.product_stock_msg, COUNT(*) AS cnt
             FROM `" . DB_PREFIX . "bg_poa_map` m
             JOIN `" . DB_PREFIX . "bg_poa_warehouse_map` w ON m.bg_id = w.bg_id AND m.poa_id = w.poa_id
             WHERE m.option_value_id = '" . $option_value_id . "'
               AND w.product_stock_msg IS NOT NULL AND TRIM(w.product_stock_msg) <> ''
             GROUP BY w.product_stock_msg
             ORDER BY cnt DESC
             LIMIT 1"
        );

        $computed = '';

        if ($query->num_rows && !empty($query->row['product_stock_msg'])) {
            $computed = $query->row['product_stock_msg'];
        } else {
            // fallback to sum of stock
            $q2 = $this->db->query(
                "SELECT COALESCE(SUM(w.product_stock),0) AS total_stock
                 FROM `" . DB_PREFIX . "bg_poa_map` m
                 JOIN `" . DB_PREFIX . "bg_poa_warehouse_map` w ON m.bg_id = w.bg_id AND m.poa_id = w.poa_id
                 WHERE m.option_value_id = '" . $option_value_id . "'"
            );
            $total = isset($q2->row['total_stock']) ? (int)$q2->row['total_stock'] : 0;
            $computed = ($total > 0) ? 'AVAILABLE' : 'SOLD_OUT';
        }

        if ($computed !== '') {
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "product_option_value`
                 SET banggood_status = '" . $this->db->escape($computed) . "'
                 WHERE option_value_id = '" . $option_value_id . "'"
            );
        }
    }

    protected function computeBasePriceFromMaps($bg_id) {
        if (empty($bg_id)) return null;

        // Try: minimum of (product_price - COALESCE(price_modifier,0)) from warehouse map
        $q = $this->db->query(
            "SELECT MIN(COALESCE(product_price,0) - COALESCE(price_modifier,0)) AS val
             FROM `" . DB_PREFIX . "bg_poa_warehouse_map`
             WHERE bg_id = '" . $this->db->escape($bg_id) . "'"
        );
        if ($q && $q->num_rows && $q->row['val'] !== null) {
            $v = (float)$q->row['val'];
            if ($v > 0) return $v;
        }

        // Fallback: minimum poa_price from bg_poa_map
        $q2 = $this->db->query(
            "SELECT MIN(COALESCE(poa_price,0)) AS val
             FROM `" . DB_PREFIX . "bg_poa_map`
             WHERE bg_id = '" . $this->db->escape($bg_id) . "'"
        );
        if ($q2 && $q2->num_rows && $q2->row['val'] !== null) {
            $v2 = (float)$q2->row['val'];
            if ($v2 > 0) return $v2;
        }

        return null;
    }

    /**
     * Ensure Ship From product_option_value rows for a product have banggood_status.
     * Uses warehouse_key -> computed message (or stock fallback) and updates the specific product's rows.
     */
    protected function syncShipFromStatusesForProduct($bg_id, $product_id, $language_id = 1) {
        if (empty($bg_id) || empty($product_id)) return;
        $bg_id_esc = $this->db->escape((string)$bg_id);
        $product_id = (int)$product_id;

        // Compute per-warehouse status for this bg_id
        $q = $this->db->query("
            SELECT warehouse_key,
                   COALESCE(NULLIF(MIN(NULLIF(product_stock_msg, '')),''), CASE WHEN SUM(product_stock)>0 THEN 'AVAILABLE' ELSE 'SOLD_OUT' END) AS computed
            FROM `" . DB_PREFIX . "bg_poa_warehouse_map`
            WHERE bg_id = '" . $bg_id_esc . "'
            GROUP BY warehouse_key
        ");
        $wh_map = array();
        if ($q->num_rows) {
            foreach ($q->rows as $r) {
                $key = trim((string)$r['warehouse_key']);
                if ($key === '') continue;
                $wh_map[$key] = $r['computed'];
            }
        }

        // Find product_option_value rows for the product where option is "Ship From"
        $ship_opt_q = $this->db->query("SELECT option_id FROM `" . DB_PREFIX . "option_description` WHERE `name` = 'Ship From' LIMIT 1");
        if (!$ship_opt_q->num_rows) return;
        $ship_option_id = (int)$ship_opt_q->row['option_id'];

        $pov_q = $this->db->query("SELECT pov.product_option_value_id, pov.option_value_id, ovd.name FROM `" . DB_PREFIX . "product_option_value` pov JOIN `" . DB_PREFIX . "option_value_description` ovd ON pov.option_value_id = ovd.option_value_id AND ovd.language_id = " . (int)$language_id . " WHERE pov.product_id = " . $product_id . " AND pov.option_id = " . $ship_option_id);
        if (!$pov_q->num_rows) return;

        foreach ($pov_q->rows as $row) {
            $pov_id = (int)$row['product_option_value_id'];
            $ov_id = (int)$row['option_value_id'];
            $ov_name = trim((string)$row['name']);
            $computed = '';

            // exact warehouse_key match
            if ($ov_name !== '' && isset($wh_map[$ov_name])) {
                $computed = $wh_map[$ov_name];
            } else {
                // try LIKE match in wh_map
                foreach ($wh_map as $k => $v) {
                    if ($k === '') continue;
                    if (stripos($ov_name, $k) !== false || stripos($k, $ov_name) !== false) { $computed = $v; break; }
                }
            }

            // fallback: compute by option_value_id via bg_poa_map -> bg_poa_warehouse_map
            if ($computed === '') {
                $q2 = $this->db->query("
                    SELECT COALESCE(NULLIF(MIN(NULLIF(w.product_stock_msg, '')),''), CASE WHEN SUM(w.product_stock) > 0 THEN 'AVAILABLE' ELSE 'SOLD_OUT' END) AS computed
                    FROM `" . DB_PREFIX . "bg_poa_map` m
                    JOIN `" . DB_PREFIX . "bg_poa_warehouse_map` w ON m.bg_id = w.bg_id AND m.poa_id = w.poa_id
                    WHERE m.option_value_id = " . (int)$ov_id . "
                ");
                if ($q2->num_rows && isset($q2->row['computed'])) $computed = $q2->row['computed'];
            }

            if ($computed !== '') {
                $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET banggood_status = '" . $this->db->escape($computed) . "' WHERE product_option_value_id = " . $pov_id);
            }
        }
    }

    /* -------------------------
       Helper: compute size sort order
       ------------------------- */
    protected function computeSizeSortOrder($value_name) {
        if (empty($value_name)) return null;
        $v = strtolower(trim($value_name));
        $v = preg_replace('/\s+/', '', $v);

        // normalize common synonyms
        $v = str_replace(array('xxs'), 'xs', $v); // treat xxs as xs
        $v = str_replace(array('xxl'), '2xl', $v);
        $v = str_replace(array('xxxl'), '3xl', $v);
        $v = str_replace(array('xlarge','extra-large','extra_large'), 'xl', $v);
        $v = preg_replace('/^size/i', '', $v); // remove leading 'size' if present

        $order = array('xs','s','m','l','xl','2xl','3xl','4xl','5xl','6xl');
        foreach ($order as $idx => $token) {
            if ($v === $token) return $idx;
            if (strpos($v, $token) === 0) return $idx;
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $v)) return $idx;
        }
        return null;
    }

    /* -------------------------
       Utility: split POA id strings into individual ids
       ------------------------- */
    protected function splitPoaIds($poa_raw) {
        $out = array();
        if ($poa_raw === null || $poa_raw === '') return $out;
        if (is_array($poa_raw)) {
            foreach ($poa_raw as $p) {
                $p = trim((string)$p);
                if ($p !== '') $out[] = $p;
            }
            return array_values(array_unique($out));
        }
        // Banggood can represent combinations using commas, pipes, semicolons, or whitespace.
        // Example: "15219|15220" or "15219,15220"
        $parts = preg_split('/[,\|\s;]+/', trim((string)$poa_raw));
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    /* -------------------------
       Robust extractor for POA quantity values from various keys/formats
       Returns int|null (null means not found)
       ------------------------- */
    protected function extractPoaQuantity($val) {
        if ($val === null) return null;
        if (!is_array($val)) return null;
        // common keys to check
        $keys = array('stock','qty','quantity','inventory','stock_num','available','available_qty','availableStock','stock_qty','count','num');
        foreach ($keys as $k) {
            if (isset($val[$k]) && $val[$k] !== '') {
                $raw = $val[$k];
                if (is_array($raw)) $raw = reset($raw);
                if ($raw === null || $raw === '') continue;
                if (is_numeric($raw)) return (int)$raw;
                if (preg_match('/(-?\d+)/', (string)$raw, $m)) return (int)$m[1];
            }
        }
        // nested candidates
        $candidates = array('warehouse_stock','stock_info','stock_list','stocks','stock_list');
        foreach ($candidates as $c) {
            if (isset($val[$c]) && is_array($val[$c])) {
                foreach ($val[$c] as $sub) {
                    if (is_array($sub)) {
                        foreach ($keys as $k2) {
                            if (isset($sub[$k2]) && is_numeric($sub[$k2])) return (int)$sub[$k2];
                            if (isset($sub[$k2]) && preg_match('/(-?\d+)/', (string)$sub[$k2], $m)) return (int)$m[1];
                        }
                    } elseif (is_numeric($sub)) return (int)$sub;
                }
            }
        }
        return null;
    }

    /* -------------------------
       API: getStocks and normalization
       ------------------------- */
    protected function getStocksForProduct($config, $bg_product_id) {
        $out = array();
        if (empty($bg_product_id)) return $out;

        try {
            $params = array('product_id' => $bg_product_id, 'lang' => $config['lang']);
            $resp = $this->apiRequest($config, 'product/getStocks', 'GET', $params);

            $stocks_root = array();
            if (isset($resp['stocks']) && is_array($resp['stocks'])) {
                $stocks_root = $resp['stocks'];
            } elseif (isset($resp['data']['stocks']) && is_array($resp['data']['stocks'])) {
                $stocks_root = $resp['data']['stocks'];
            } elseif (isset($resp['data']) && is_array($resp['data'])) {
                if (isset($resp['data']['stock_list']) || isset($resp['data']['stocks'])) {
                    $stocks_root = array($resp['data']);
                } elseif ($this->isAssociative($resp['data'])) {
                    $stocks_root = array($resp['data']);
                }
            }

            foreach ($stocks_root as $warehouse_block) {
                $warehouse_name = isset($warehouse_block['warehouse']) ? $warehouse_block['warehouse'] : (isset($warehouse_block['warehouse_name']) ? $warehouse_block['warehouse_name'] : '');
                $list_key = 'stock_list';
                if (!isset($warehouse_block[$list_key]) && isset($warehouse_block['stocks_list'])) $list_key = 'stocks_list';
                if (!isset($warehouse_block[$list_key]) && isset($warehouse_block['stock_list'])) $list_key = 'stock_list';
                if (!isset($warehouse_block[$list_key]) && isset($warehouse_block['stocks'])) $list_key = 'stocks';

                if (isset($warehouse_block[$list_key]) && is_array($warehouse_block[$list_key])) {
                    foreach ($warehouse_block[$list_key] as $sl) {
                        $poa_id = isset($sl['poa_id']) ? (string)$sl['poa_id'] : (isset($sl['poaId']) ? (string)$sl['poaId'] : '');
                        $poa = isset($sl['poa']) ? (string)$sl['poa'] : (isset($sl['poa_name']) ? (string)$sl['poa_name'] : '');
                        $stock = 0;
                        if (isset($sl['stock']) && is_numeric($sl['stock'])) $stock = (int)$sl['stock'];
                        elseif (isset($sl['stock_num']) && is_numeric($sl['stock_num'])) $stock = (int)$sl['stock_num'];
                        else {
                            if (preg_match('/(-?\d+)/', (string)(isset($sl['stock']) ? $sl['stock'] : ''), $m)) $stock = (int)$m[1];
                        }
                        $stock_msg = isset($sl['stock_msg']) ? $sl['stock_msg'] : (isset($sl['stocks_msg']) ? $sl['stocks_msg'] : '');
                        $out[] = array(
                            'warehouse' => $warehouse_name,
                            'poa_id'    => $poa_id,
                            'poa'       => $poa,
                            'stock'     => $stock,
                            'stock_msg' => $stock_msg
                        );
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Banggood getStocks error: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Normalize a Banggood stock message into a stable token (preferred),
     * so we can persist it and convert consistently in the frontend.
     *
     * - If message already looks like LC_STOCK_MSG_*, keep as-is.
     * - If message is English like "In stock, usually dispatched in 1 business day",
     *   convert to LC_STOCK_MSG_1_DAYS.
     * - If message indicates sold/out/expect, convert accordingly.
     */
    protected function normalizeStockStatusToken($msg) {
        if ($msg === null) return null;
        $msg = trim((string)$msg);
        if ($msg === '') return null;

        // Already a token?
        if (preg_match('/^LC_STOCK_MSG_/i', $msg)) return $msg;

        $low = strtolower($msg);
        if (strpos($low, 'sold out') !== false || strpos($low, 'out of stock') !== false) {
            return 'LC_STOCK_MSG_SOLD_OUT';
        }
        if (strpos($low, 'expect') !== false || strpos($low, 'expected') !== false || strpos($low, 'backorder') !== false) {
            return 'LC_STOCK_MSG_EXPECT';
        }

        // Common Banggood phrasing
        if (preg_match('/dispatched in\s+(\d+)\s+business\s+day/i', $msg, $m)) {
            $d = (int)$m[1];
            if ($d > 0) return 'LC_STOCK_MSG_' . $d . '_DAYS';
        }
        if (preg_match('/dispatched in\s+(\d+)\s+days?/i', $msg, $m)) {
            $d = (int)$m[1];
            if ($d > 0) return 'LC_STOCK_MSG_' . $d . '_DAYS';
        }
        if (preg_match('/dispatched in\s+(\d+)\s+hours?/i', $msg, $m)) {
            $h = (int)$m[1];
            if ($h > 0) return 'LC_STOCK_MSG_' . $h . '_HOURS';
        }

        // Fallback: store raw message so at least something shows.
        return $msg;
    }

    /* -------------------------
       Apply stocks (core logic)
       - Prefers per-option quantities extracted from product raw poa_list (applied earlier)
       - Reconciles with getStocks API but avoids zeroing existing non-zero option quantities when API returns all zeros.
       - Computes warehouse/product total as sum of stock rows (each combination row counted once)
       ------------------------- */
   protected function applyStocksToProduct($bg_id, $product_id, $config) {
    $this->ensureBgPoaWarehouseMapTableExists();

    $diagnostics = array(
        'bg_id' => $bg_id,
        'product_id' => (int)$product_id,
        'per_poa' => array(),
        'updated_pov_ids' => array(),
        'inserted_pov_count' => 0,
        'unmapped_poa' => array(),
        'total_api_stock' => 0,
        'warnings' => array()
    );

    if (empty($bg_id) || empty($product_id)) {
        $diagnostics['warnings'][] = 'Missing bg_id or product_id';
        $this->writeDebugLog($bg_id, $diagnostics);
        return $diagnostics;
    }

    try {
        $stocks = $this->getStocksForProduct($config, $bg_id);
        if (empty($stocks)) {
            $diagnostics['warnings'][] = 'getStocks returned no rows';
            $this->writeDebugLog($bg_id, $diagnostics);
            return $diagnostics;
        }

        $this->ensureBgPoaMapTableExists();

        // Aggregate per individual POA id from combination rows, and compute total_api_stock
        $per_poa = array();
        $total_api_stock = 0;
        foreach ($stocks as $s) {
            $poa_raw = isset($s['poa_id']) ? (string)$s['poa_id'] : '';
            $poa_text = isset($s['poa']) ? (string)$s['poa'] : '';
            $stock = isset($s['stock']) ? (int)$s['stock'] : 0;
            $stock_msg = isset($s['stock_msg']) ? (string)$s['stock_msg'] : '';
            // count combination row once towards total
            $total_api_stock += $stock;
            if ($poa_raw === '') continue;
            $ids = $this->splitPoaIds($poa_raw);
            foreach ($ids as $poa_id) {
                if (!isset($per_poa[$poa_id])) $per_poa[$poa_id] = array('qty' => 0, 'poa_text' => $poa_text, 'stock_msgs' => array());
                // aggregate for diagnostic and per-option quantity (sum of combination rows that include the POA)
                $per_poa[$poa_id]['qty'] += $stock;
                if (!empty($stock_msg)) $per_poa[$poa_id]['stock_msgs'][] = $stock_msg;
                if (empty($per_poa[$poa_id]['poa_text']) && !empty($poa_text)) $per_poa[$poa_id]['poa_text'] = $poa_text;
            }

            // Persist per-warehouse mapping row(s) for this stock row (bg_poa_warehouse_map)
            $warehouse_key = isset($s['warehouse']) ? (string)$s['warehouse'] : (isset($s['warehouse_name']) ? (string)$s['warehouse_name'] : '');
            foreach ($ids as $poa_id_for_map) {
                if ($poa_id_for_map === '') continue;
                $allow_map_writes = (bool)$this->config->get('module_banggood_import_allow_map_writes');
                if ($allow_map_writes) {
                    $this->db->query(
                        "INSERT INTO `" . DB_PREFIX . "bg_poa_warehouse_map`
                         (bg_id, poa_id, warehouse_key, product_price, price_modifier, product_stock, product_stock_msg)
                         VALUES ('" . $this->db->escape($bg_id) . "', '" . $this->db->escape($poa_id_for_map) . "', '" . $this->db->escape($warehouse_key) . "', 0.00, 0.00, '" . (int)$stock . "', '" . $this->db->escape($stock_msg) . "')
                         ON DUPLICATE KEY UPDATE
                           product_stock = VALUES(product_stock),
                           product_stock_msg = VALUES(product_stock_msg),
                           product_price = VALUES(product_price),
                           price_modifier = VALUES(price_modifier)"
                    );
                }
            }
        }

        $diagnostics['per_poa'] = $per_poa;
        $diagnostics['total_api_stock'] = $total_api_stock;

        // Sync banggood_status for each option_value_id mapped for this bg_id
        $map_rows = $this->db->query(
            "SELECT DISTINCT option_value_id FROM `" . DB_PREFIX . "bg_poa_map`
             WHERE bg_id = '" . $this->db->escape($bg_id) . "' AND option_value_id IS NOT NULL AND option_value_id <> 0"
        );
        if ($map_rows && $map_rows->num_rows) {
            foreach ($map_rows->rows as $mr) {
                $ov_id = (int)$mr['option_value_id'];
                if ($ov_id) {
                    $this->syncOptionValueBanggoodData($ov_id);
                }
            }
        }

        // Safeguard: if API reports zero total stock but product already has non-zero option quantities, avoid overwriting with zeros.
        if ($total_api_stock === 0) {
            $qexist = $this->db->query("SELECT COUNT(*) AS cnt FROM `" . DB_PREFIX . "product_option_value` WHERE product_id = " . (int)$product_id . " AND quantity > 0");
            $existing_nonzero = (isset($qexist->row['cnt']) ? (int)$qexist->row['cnt'] : 0);
            if ($existing_nonzero > 0) {
                $diagnostics['warnings'][] = 'API total stock == 0 and existing non-zero option quantities present; skipping overwrite to preserve existing values';
                $diagnostics['existing_nonzero_pov_count'] = $existing_nonzero;
                $this->writeDebugLog($bg_id, $diagnostics);
                return $diagnostics;
            }
        }

        if (empty($per_poa)) {
            // fallback text matching if no per_poa parsed
            foreach ($stocks as $s) {
                $poa_text = isset($s['poa']) ? (string)$s['poa'] : (isset($s['poa_name']) ? (string)$s['poa_name'] : '');
                $stock = isset($s['stock']) ? (int)$s['stock'] : 0;
                if ($poa_text === '') continue;
                $q2 = $this->db->query(
                    "SELECT ov.option_value_id FROM `" . DB_PREFIX . "option_value_description` ovd
                     JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = ovd.option_value_id
                     WHERE ovd.name = '" . $this->db->escape($poa_text) . "' LIMIT 1"
                );
                $allow_pov_overwrite = $this->allowPovOverwrite();
                if (!$allow_pov_overwrite && $total_api_stock > 0) $allow_pov_overwrite = true;

                if ($q2->num_rows) {
                    $option_value_id = (int)$q2->row['option_value_id'];
                    if ($allow_pov_overwrite) {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . (int)$stock . "' WHERE product_id = '" . (int)$product_id . "' AND option_value_id = '" . (int)$option_value_id . "'");
                    } else {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . (int)$stock . "' WHERE product_id = '" . (int)$product_id . "' AND option_value_id = '" . (int)$option_value_id . "' AND quantity = 0");
                    }
                    $diagnostics['updated_pov_ids'][] = $option_value_id;
                } else {
                    $q3 = $this->db->query(
                        "SELECT ov.option_value_id FROM `" . DB_PREFIX . "option_value_description` ovd
                         JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = ovd.option_value_id
                         WHERE ovd.name LIKE '%" . $this->db->escape($poa_text) . "%' LIMIT 1"
                    );
                    if ($q3->num_rows) {
                        $option_value_id = (int)$q3->row['option_value_id'];
                        if ($allow_pov_overwrite) {
                            $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . (int)$stock . "' WHERE product_id = '" . (int)$product_id . "' AND option_value_id = '" . (int)$option_value_id . "'");
                        } else {
                            $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . (int)$stock . "' WHERE product_id = '" . (int)$product_id . "' AND option_value_id = '" . (int)$option_value_id . "' AND quantity = 0");
                        }
                        $diagnostics['updated_pov_ids'][] = $option_value_id;
                    } else {
                        $diagnostics['unmapped_poa'][] = array('poa_text' => $poa_text, 'stock' => $stock);
                    }
                }
            }
            // set product quantity to total_api_stock (combination-level sum)
            $final_qty = ($total_api_stock > 0 ? $total_api_stock : 0);
            // Requirement: imported products must always be enabled, regardless of stock level.
            $status = 1;

            // determine stock_status_id to show in admin
            $stock_status_id = ($final_qty > 0) ? (int)$this->config->get('config_stock_status_id') : (int)$this->detectOutOfStockStatusId();

            $this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = '" . (int)$final_qty . "', status = '" . (int)$status . "', stock_status_id = '" . (int)$stock_status_id . "' WHERE product_id = " . (int)$product_id);
            $this->writeDebugLog($bg_id, $diagnostics);
            return $diagnostics;
        }

        // Insert per_poa into temporary table (for joins)
        $collate = 'utf8_general_ci';
        $this->db->query("DROP TEMPORARY TABLE IF EXISTS temp_poa_qty");
        $this->db->query("CREATE TEMPORARY TABLE temp_poa_qty (poa_id VARCHAR(64) COLLATE " . $collate . " NOT NULL PRIMARY KEY, qty INT NOT NULL) ENGINE=Memory DEFAULT CHARSET=utf8 COLLATE " . $collate);

        foreach ($per_poa as $poa => $d) {
            $this->db->query("REPLACE INTO temp_poa_qty (poa_id, qty) VALUES ('" . $this->db->escape($poa) . "', " . (int)$d['qty'] . ")");
        }

        /**
         * Build a POA->option_value_id map that does NOT depend on bg_poa_map writes.
         * We populate a temporary table temp_poa_map that combines:
         * - existing bg_poa_map rows (if any)
         * - reconstructed mappings from GetProductInfo (poa_list -> poa_id + poa_name)
         */
        $this->db->query("DROP TEMPORARY TABLE IF EXISTS temp_poa_map");
        $this->db->query("CREATE TEMPORARY TABLE temp_poa_map (poa_id VARCHAR(64) COLLATE " . $collate . " NOT NULL PRIMARY KEY, option_value_id INT NOT NULL) ENGINE=Memory DEFAULT CHARSET=utf8 COLLATE " . $collate);

        // Seed from bg_poa_map if available
        try {
            $seed_rows = $this->db->query(
                "SELECT poa_id, option_value_id FROM `" . DB_PREFIX . "bg_poa_map`
                 WHERE bg_id = '" . $this->db->escape((string)$bg_id) . "'
                   AND option_value_id IS NOT NULL AND option_value_id <> 0"
            );
            if ($seed_rows && $seed_rows->num_rows) {
                foreach ($seed_rows->rows as $sr) {
                    $pid = isset($sr['poa_id']) ? (string)$sr['poa_id'] : '';
                    $ov  = isset($sr['option_value_id']) ? (int)$sr['option_value_id'] : 0;
                    if ($pid === '' || $ov <= 0) continue;
                    $this->db->query("REPLACE INTO temp_poa_map (poa_id, option_value_id) VALUES ('" . $this->db->escape($pid) . "', " . (int)$ov . ")");
                }
            }
        } catch (Exception $e) {
            // ignore
        }

        // Reconstruct from GetProductInfo (authoritative POA ids + names)
        try {
            $product_detail = $this->fetchProductDetail($config, $bg_id);
            $poa_list = array();
            if (is_array($product_detail) && isset($product_detail['poa_list']) && is_array($product_detail['poa_list'])) {
                $poa_list = $product_detail['poa_list'];
            } elseif (is_array($product_detail) && isset($product_detail['data']['poa_list']) && is_array($product_detail['data']['poa_list'])) {
                $poa_list = $product_detail['data']['poa_list'];
            }
            if (!empty($poa_list)) {
                foreach ($poa_list as $group) {
                    if (!is_array($group)) continue;
                    $option_name = isset($group['option_name']) ? (string)$group['option_name'] : (isset($group['name']) ? (string)$group['name'] : '');
                    $option_name = $this->normalizeImportedOptionName($option_name);
                    if ($option_name === '') continue;
                    $option_id = $this->getOptionIdByName($option_name);
                    if (!$option_id) continue;
                    $values = array();
                    if (isset($group['option_values']) && is_array($group['option_values'])) $values = $group['option_values'];
                    elseif (isset($group['values']) && is_array($group['values'])) $values = $group['values'];
                    foreach ($values as $val) {
                        if (!is_array($val)) continue;
                        $poa_id_raw = isset($val['poa_id']) ? (string)$val['poa_id'] : (isset($val['poaId']) ? (string)$val['poaId'] : '');
                        $value_name = isset($val['poa_name']) ? (string)$val['poa_name'] : (isset($val['name']) ? (string)$val['name'] : '');
                        if ($poa_id_raw === '' || $value_name === '') continue;
                        $ov_id = $this->getOptionValueIdByName($option_id, $value_name);
                        if (!$ov_id) continue;
                        foreach ($this->splitPoaIds($poa_id_raw) as $pid) {
                            if ($pid === '') continue;
                            $this->db->query("REPLACE INTO temp_poa_map (poa_id, option_value_id) VALUES ('" . $this->db->escape((string)$pid) . "', " . (int)$ov_id . ")");
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // ignore; we'll fall back to text matching below
        }

        $this->db->query("START TRANSACTION");

        // Guarded update: Update existing mapped product_option_value rows via join using temp_poa_map mapping
        $allow_pov_overwrite = $this->allowPovOverwrite();
        if (!$allow_pov_overwrite && $total_api_stock > 0) $allow_pov_overwrite = true;

        if ($allow_pov_overwrite) {
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "product_option_value` pov
                 JOIN temp_poa_map m ON pov.option_value_id = m.option_value_id
                 JOIN temp_poa_qty t ON m.poa_id COLLATE " . $collate . " = t.poa_id COLLATE " . $collate . "
                 SET pov.quantity = t.qty
                 WHERE pov.product_id = '" . (int)$product_id . "'"
            );
        } else {
            // only overwrite where existing quantity is zero to preserve prior values
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "product_option_value` pov
                 JOIN temp_poa_map m ON pov.option_value_id = m.option_value_id
                 JOIN temp_poa_qty t ON m.poa_id COLLATE " . $collate . " = t.poa_id COLLATE " . $collate . "
                 SET pov.quantity = t.qty
                 WHERE pov.product_id = '" . (int)$product_id . "' AND pov.quantity = 0"
            );
        }

        $diagnostics['after_update_affected_rows'] = $this->db->countAffected();

        // Ensure product_option rows exist
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "product_option` (product_id, option_id, `value`, required)
             SELECT DISTINCT " . (int)$product_id . " AS product_id, ov.option_id, '' AS `value`, 1 AS required
             FROM temp_poa_map m
             JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = m.option_value_id
             LEFT JOIN `" . DB_PREFIX . "product_option` po ON po.product_id = " . (int)$product_id . " AND po.option_id = ov.option_id
             WHERE po.product_option_id IS NULL"
        );

        // Insert missing product_option_value rows with the temp quantities
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "product_option_value` (product_option_id, product_id, option_id, option_value_id, quantity, subtract, price, price_prefix, points, points_prefix, weight, weight_prefix)
             SELECT po.product_option_id, " . (int)$product_id . " AS product_id, ov.option_id, m.option_value_id, t.qty, 1, 0.00, '+', 0, '+', 0, '+'
             FROM temp_poa_map m
             JOIN temp_poa_qty t ON t.poa_id = m.poa_id
             JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = m.option_value_id
             JOIN `" . DB_PREFIX . "product_option` po ON po.product_id = " . (int)$product_id . " AND po.option_id = ov.option_id
             LEFT JOIN `" . DB_PREFIX . "product_option_value` pov ON pov.product_id = " . (int)$product_id . " AND pov.option_value_id = m.option_value_id
             WHERE pov.product_option_value_id IS NULL"
        );
        $diagnostics['inserted_pov_count'] = $this->db->countAffected();

        // For unmapped poas try text-match and persist mapping
        $unmapped = $this->db->query(
            "SELECT t.poa_id, t.qty FROM temp_poa_qty t
             LEFT JOIN temp_poa_map m ON m.poa_id = t.poa_id
             WHERE m.option_value_id IS NULL"
        );

        if ($unmapped && $unmapped->num_rows) {
            foreach ($unmapped->rows as $urow) {
                $poa_id = $urow['poa_id'];
                $qty = (int)$urow['qty'];
                $poa_text = isset($per_poa[$poa_id]['poa_text']) ? $per_poa[$poa_id]['poa_text'] : '';

                $matched_option_value_id = 0;
                if (!empty($poa_text)) {
                    $q2 = $this->db->query(
                        "SELECT ov.option_value_id FROM `" . DB_PREFIX . "option_value_description` ovd
                         JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = ovd.option_value_id
                         WHERE ovd.name = '" . $this->db->escape($poa_text) . "' LIMIT 1"
                    );
                    if ($q2->num_rows) $matched_option_value_id = (int)$q2->row['option_value_id'];
                    else {
                        $q3 = $this->db->query(
                            "SELECT ov.option_value_id FROM `" . DB_PREFIX . "option_value_description` ovd
                             JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = ovd.option_value_id
                             WHERE ovd.name LIKE '%" . $this->db->escape($poa_text) . "%' LIMIT 1"
                        );
                        if ($q3->num_rows) $matched_option_value_id = (int)$q3->row['option_value_id'];
                    }
                }

                if ($matched_option_value_id) {
                    if ($allow_pov_overwrite) {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . (int)$qty . "' WHERE product_id = '" . (int)$product_id . "' AND option_value_id = '" . (int)$matched_option_value_id . "'");
                    } else {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . (int)$qty . "' WHERE product_id = '" . (int)$product_id . "' AND option_value_id = '" . (int)$matched_option_value_id . "' AND quantity = 0");
                    }
                    $diagnostics['updated_pov_ids'][] = $matched_option_value_id;
                    // keep temp mapping consistent for the remainder of this run
                    $this->db->query("REPLACE INTO temp_poa_map (poa_id, option_value_id) VALUES ('" . $this->db->escape($poa_id) . "', '" . (int)$matched_option_value_id . "')");
                } else {
                    $diagnostics['unmapped_poa'][] = array('poa_id' => $poa_id, 'qty' => $qty, 'poa_text' => $poa_text);
                }
            }
        }

        // Additional direct mapping pass using temp_poa_map rows - update product_option_value quantities
        $map_rows = $this->db->query("SELECT poa_id, option_value_id FROM temp_poa_map")->rows;
        if ($map_rows) {
            foreach ($map_rows as $mr) {
                $poa_id = (string)$mr['poa_id'];
                $optval_id = (int)$mr['option_value_id'];
                if (isset($per_poa[$poa_id]) && isset($per_poa[$poa_id]['qty'])) {
                    $qty = (int)$per_poa[$poa_id]['qty'];
                    if ($allow_pov_overwrite) {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . $qty . "' WHERE product_id = '" . (int)$product_id . "' AND option_value_id = " . $optval_id);
                    } else {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . $qty . "' WHERE product_id = '" . (int)$product_id . "' AND option_value_id = " . $optval_id . " AND quantity = 0");
                    }
                    $diagnostics['updated_pov_ids'][] = $optval_id;
                }
            }
        }

        // Recompute product total quantity from product_option_value rows and/or set to total_api_stock
        $stock_status_to_set = ($total_api_stock > 0) ? (int)$this->config->get('config_stock_status_id') : (int)$this->detectOutOfStockStatusId();
        // Requirement: imported products must always be enabled, regardless of stock level.
        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = '" . (int)$total_api_stock . "', status = 1, stock_status_id = '" . (int)$stock_status_to_set . "' WHERE product_id = " . (int)$product_id);

        $this->db->query("COMMIT");

        // Upsert product_variant rows derived from per_poa aggregates and bg_poa_map when needed.
        // IMPORTANT: we build combination-level variants that match the bg_stock_check output.
        try {
            $this->upsertProductVariantsFromBgPoa($bg_id, (int)$product_id, $stocks);
        } catch (Exception $e) {
            $diagnostics['warnings'][] = 'upsertProductVariantsFromBgPoa exception: ' . $e->getMessage();
            $this->writeDebugLog($bg_id, $diagnostics);
        }

        // Force product enabled and date_available set to yesterday regardless of computed stock
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        try {
            $this->db->query("UPDATE `" . DB_PREFIX . "product` SET `status` = 1, `date_available` = '" . $this->db->escape($yesterday) . "' WHERE product_id = '" . (int)$product_id . "'");
        } catch (Exception $e) {
            error_log('Banggood: failed to force-enable product ' . (int)$product_id . ' - ' . $e->getMessage());
        }

        $diagnostics['final_product_qty'] = $this->db->query("SELECT quantity, status, stock_status_id FROM `" . DB_PREFIX . "product` WHERE product_id = " . (int)$product_id . " LIMIT 1")->row;
        $diagnostics['per_poa'] = $per_poa;
        $this->writeDebugLog($bg_id, $diagnostics);

        $this->db->query("DROP TEMPORARY TABLE IF EXISTS temp_poa_qty");
        $this->db->query("DROP TEMPORARY TABLE IF EXISTS temp_poa_map");
    } catch (Exception $e) {
        try { $this->db->query("ROLLBACK"); } catch (Exception $x) {}
        $diagnostics['warnings'][] = 'Exception: ' . $e->getMessage();
        $this->writeDebugLog($bg_id, $diagnostics);
    }

    return $diagnostics;
}
    /* -------------------------
       Debug logger (tries multiple locations then error_log)
       ------------------------- */
    protected function writeDebugLog($bg_id, array $diagnostics) {
        // Disabled to prevent log files from consuming disk space.
        // (Import behavior is unaffected; errors still surface via UI/Exceptions.)
        return;

        $filename = 'banggood_import_debug_' . preg_replace('/[^0-9A-Za-z_.-]/', '_', $bg_id) . '.log';
        $entry = array('ts' => date('c'), 'diagnostics' => $diagnostics);
        $json = json_encode($entry, JSON_PRETTY_PRINT) . PHP_EOL;
        $candidates = array();

        if (defined('DIR_STORAGE') && DIR_STORAGE) $candidates[] = rtrim(DIR_STORAGE, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (defined('DIR_APPLICATION') && DIR_APPLICATION) $candidates[] = rtrim(DIR_APPLICATION, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $filename;
        if (defined('DIR_SYSTEM') && DIR_SYSTEM) {
            $candidates[] = rtrim(DIR_SYSTEM, '/\\') . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $filename;
            $candidates[] = rtrim(DIR_SYSTEM, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $filename;
        }
        if (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']) {
            $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $filename;
            $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $filename;
        }
        $tmp = sys_get_temp_dir();
        if ($tmp) $candidates[] = rtrim($tmp, '/\\') . DIRECTORY_SEPARATOR . $filename;

        $written = false;
        foreach ($candidates as $path) {
            $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
            $dir = dirname($path);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $res = @file_put_contents($path, $json, FILE_APPEND | LOCK_EX);
            if ($res !== false) {
                $diagnostics['_written_to'] = $path;
                $written = true;
                break;
            }
        }

        if (!$written) {
            @error_log("BANGGOOD-IMPORT-LOG: Could not write debug file. Diagnostics: " . json_encode($diagnostics));
            @error_log("BANGGOOD-IMPORT-LOG-CONTENT: " . (is_string($json) ? substr($json, 0, 1000) : ''));
        } else {
            @error_log("BANGGOOD-IMPORT-LOG: wrote debug for bg_id={$bg_id} to " . $diagnostics['_written_to']);
        }
    }

    /* -------------------------
       Banggood configuration helper (returns base_url, app_id, app_secret, lang, currency)
       - robustly reads common module settings with fallbacks
       ------------------------- */
    protected function getBanggoodConfig() {
        // Common module setting keys (try multiple naming conventions)
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

    /* -------------------------
       Allow POV overwrite flag helper
       ------------------------- */
    protected function allowPovOverwrite() {
        // Default: false. Set module_banggood_import_overwrite_pov = 1 to enable overwrites.
        return (bool)$this->config->get('module_banggood_import_overwrite_pov');
    }

    /* -------------------------
       Public import entry points + API helpers
       ------------------------- */
    public function importCategory($category_id, $max_products = 0) {
        // Controlled mapping writes: only enable mapping-table writes when the admin enables the config flag.
        // Default: disabled -> your DB triggers will block bg_poa_map/bg_poa_warehouse_map writes.
        $allow_map_writes = (bool)$this->config->get('module_banggood_import_allow_map_writes');

        if ($allow_map_writes) {
            try { $this->db->query("SET @bg_allow_write = 1"); } catch (Exception $e) {}
        }

        try {
            $this->load->model('catalog/product');
            $config = $this->getBanggoodConfig();
            $created = 0; $updated = 0; $page = 1; $page_size = 20; $total_fetched = 0;

            while (true) {
                $products_raw = $this->fetchCategoryPage($config, $category_id, $page, $page_size);
                if (!$products_raw) break;
                foreach ($products_raw as $raw) {
                    $normalized = $this->normalizeProduct($raw, $config);
                    $result = $this->upsertProduct($normalized);
                    if ($result === 'created') $created++; elseif ($result === 'updated') $updated++;
                    $total_fetched++;
                    if ($max_products && $total_fetched >= $max_products) break 2;
                }
                if (count($products_raw) < $page_size) break;
                $page++;
                usleep(200000);
            }

            return array('created' => $created, 'updated' => $updated);
        } finally {
            if ($allow_map_writes) {
                try { $this->db->query("SET @bg_allow_write = NULL"); } catch (Exception $e) {}
            }
        }
    }

    public function importProductUrl($product_url) {
        // Controlled mapping writes: only enable mapping-table writes when the admin enables the config flag.
        $allow_map_writes = (bool)$this->config->get('module_banggood_import_allow_map_writes');

        if ($allow_map_writes) {
            try { $this->db->query("SET @bg_allow_write = 1"); } catch (Exception $e) {}
        }

        try {
            $this->load->model('catalog/product');
            $config = $this->getBanggoodConfig();
            $product_id = $this->extractProductIdFromUrl($product_url);
            if (!$product_id) throw new Exception('Cannot extract product ID from URL: ' . $product_url);
            $raw = $this->fetchProductDetail($config, $product_id);
            $normalized = $this->normalizeProduct($raw, $config);
            $result = $this->upsertProduct($normalized);
            return array('created' => $result === 'created', 'updated' => $result === 'updated');
        } finally {
            if ($allow_map_writes) {
                try { $this->db->query("SET @bg_allow_write = NULL"); } catch (Exception $e) {}
            }
        }
    }

    protected function extractProductIdFromUrl($url) {
        if (preg_match(self::PRODUCT_URL_REGEX_1, $url, $m)) return $m[1];
        if (preg_match(self::PRODUCT_URL_REGEX_2, $url, $m)) return $m[1];
        return null;
    }

    protected function fetchCategoryPage($config, $category_id, $page, $page_size) {
        $task = 'product/getProductList';
        $params = array('category' => $category_id, 'page' => $page, 'pagesize' => $page_size, 'lang' => $config['lang'], 'currency' => $config['currency']);
        $response = $this->apiRequest($config, $task, 'GET', $params);
        if (!isset($response['data']) || !is_array($response['data'])) return array();
        if (isset($response['data']['list']) && is_array($response['data']['list'])) return $response['data']['list'];
        if (isset($response['data']['products']) && is_array($response['data']['products'])) return $response['data']['products'];
        return array();
    }

    protected function fetchProductDetail($config, $product_id) {
        $task = 'product/getProductInfo';
        $params = array('product_id' => $product_id, 'lang' => $config['lang'], 'currency' => $config['currency']);
        $response = $this->apiRequest($config, $task, 'GET', $params);

        if ((isset($response['code']) && (int)$response['code'] === 0) && (isset($response['product_name']) || isset($response['product']))) {
            $response['product_id'] = $product_id;
            return $response;
        }
        if (isset($response['data']['product']) && is_array($response['data']['product'])) return $response['data']['product'];
        if (isset($response['data']) && is_array($response['data']) && $this->isAssociative($response['data'])) return $response['data'];
        if (isset($response['product']) && is_array($response['product'])) return $response['product'];
        throw new Exception('Banggood API returned no usable product data for product ID ' . $product_id);
    }

    protected function isAssociative(array $arr) {
        if ([] === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
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

        $attempt = 0; $maxAttempts = 2;
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            if (strtoupper($method) === 'POST') {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&'));
            }
            $result = curl_exec($ch);
            if ($result === false) { $err = curl_error($ch); curl_close($ch); throw new Exception('Banggood API curl error: ' . $err); }
            curl_close($ch);
            $data = json_decode($result, true);
            if (!is_array($data)) { throw new Exception('Banggood API returned invalid JSON: ' . $result); }

            if (isset($data['code']) && (int)$data['code'] === 21020 && $attempt < $maxAttempts) {
                $this->clearAccessTokenCache();
                $access_token = $this->getAccessToken($config);
                if ($task !== 'getAccessToken') { $params['access_token'] = $access_token; if (empty($params['lang'])) $params['lang'] = $config['lang']; if (empty($params['currency'])) $params['currency'] = $config['currency']; }
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

    /**
     * apiRequestRaw: returns decoded array when response is JSON, otherwise returns raw string.
     * Accepts $task as either a task/path (appended to base_url) or a full URL (starting with http).
     */
    protected function apiRequestRaw($config, $task, $method = 'GET', $params = array()) {
        // Allow $task to be a full URL
        if (preg_match('#^https?://#i', $task)) {
            $url = $task;
        } else {
            // if task starts with slash, append without duplicate slash
            if (strpos($task, '/') === 0) {
                $url = rtrim($config['base_url'], '/') . $task;
            } else {
                $url = rtrim($config['base_url'], '/') . '/' . ltrim($task, '/');
            }
        }

        if (strtoupper($method) === 'GET' && !empty($params)) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'OpenCart-Banggood-Client');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if (strtoupper($method) === 'POST') { curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&')); }
        $result = curl_exec($ch);
        if ($result === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Banggood API curl error: ' . $err);
        }
        curl_close($ch);

        // Try decode JSON; if not JSON return raw string for callers that can handle it
        $data = json_decode($result, true);
        if (is_array($data)) {
            return $data;
        }

        // Return raw string (calling code can attempt to parse or use findCandidateInResponse which accepts strings)
        return $result;
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

        // Ensure we received JSON as an array
        if (!is_array($data)) {
            $snippet = is_string($data) ? substr($data, 0, 1000) : '';
            throw new Exception('Banggood getAccessToken returned invalid response: ' . $snippet);
        }

        if (!isset($data['code']) || (int)$data['code'] !== 0) {
            $msg = isset($data['msg']) ? $data['msg'] : (isset($data['message']) ? $data['message'] : '');
            throw new Exception('Banggood getAccessToken error: code=' . (isset($data['code']) ? $data['code'] : 'unknown') . ' msg=' . $msg);
        }
        if (empty($data['access_token']) || empty($data['expires_in'])) throw new Exception('Banggood getAccessToken returned invalid data');
        $expireTime = time() + (int)$data['expires_in'];
        $accessTokenArr = array('accessToken' => $data['access_token'], 'expireTime' => $expireTime, 'expireDateTime' => date('Y-m-d H:i:s', $expireTime));
        $cacheStr = "<?php\nreturn " . var_export($accessTokenArr, true) . ";";
        file_put_contents($this->token_cache_file, $cacheStr);
        return $data['access_token'];
    }

    protected function clearAccessTokenCache() {
        if (is_file($this->token_cache_file)) @unlink($this->token_cache_file);
    }

    /* -------------------------
       Product normalize / upsert / create / update
       ------------------------- */
    protected function normalizeProduct($raw, $config) {
        $product_id = '';
        if (isset($raw['product_id'])) $product_id = $raw['product_id'];
        elseif (isset($raw['goods_id'])) $product_id = $raw['goods_id'];
        elseif (isset($raw['id'])) $product_id = $raw['id'];

        $title = isset($raw['title']) ? $raw['title'] : (isset($raw['name']) ? $raw['name'] : (isset($raw['product_name']) ? $raw['product_name'] : ''));
        $description = isset($raw['description']) ? $raw['description'] : (isset($raw['desc']) ? $raw['desc'] : (isset($raw['product_desc']) ? $raw['product_desc'] : ''));

        // Determine product base price (prefer sale/discount fields, then price, then retail_price)
        $price = null;
        if (isset($raw['sale_price']) && $raw['sale_price'] !== '') {
            $price = (float)$raw['sale_price'];
        } elseif (isset($raw['discount_price']) && $raw['discount_price'] !== '') {
            $price = (float)$raw['discount_price'];
        } elseif (isset($raw['price']) && $raw['price'] !== '') {
            $price = (float)$raw['price'];
        } elseif (isset($raw['retail_price']) && $raw['retail_price'] !== '') {
            $price = (float)$raw['retail_price'];
        }

        // If price still null, try warehouse_list and prefer a US warehouse (or the first entry)
        if ($price === null && isset($raw['warehouse_list']) && is_array($raw['warehouse_list']) && count($raw['warehouse_list']) > 0) {
            $found = null;
            foreach ($raw['warehouse_list'] as $w) {
                if ((isset($w['site']) && strtoupper($w['site']) === 'US')
                    || (isset($w['warehouse_name']) && stripos($w['warehouse_name'], 'US') !== false)
                    || (isset($w['country']) && strtoupper($w['country']) === 'US')
                ) {
                    if (isset($w['warehouse_price']) && $w['warehouse_price'] !== '') {
                        $found = (float)$w['warehouse_price'];
                        break;
                    }
                }
            }
            // fallback to first warehouse entry if no US-specific found
            if ($found === null) {
                if (isset($raw['warehouse_list'][0]['warehouse_price']) && $raw['warehouse_list'][0]['warehouse_price'] !== '') {
                    $found = (float)$raw['warehouse_list'][0]['warehouse_price'];
                } elseif (isset($raw['warehouse_list'][0]['price']) && $raw['warehouse_list'][0]['price'] !== '') {
                    $found = (float)$raw['warehouse_list'][0]['price'];
                }
            }
            if ($found !== null) $price = (float)$found;
        }

        // --- Price fallback: if still null, derive from POA-level or warehouse_list prices (choose min)
        if ($price === null) {
            $poa_candidates = array();
            if (!empty($raw['poa_list']) && is_array($raw['poa_list'])) {
                foreach ($raw['poa_list'] as $group) {
                    $values = array();
                    if (!empty($group['option_values']) && is_array($group['option_values'])) $values = $group['option_values'];
                    elseif (!empty($group['values']) && is_array($group['values'])) $values = $group['values'];
                    foreach ($values as $v) {
                        $pv = null;
                        if (isset($v['poa_price']) && $v['poa_price'] !== '') $pv = $v['poa_price'];
                        elseif (isset($v['price']) && $v['price'] !== '') $pv = $v['price'];
                        if ($pv !== null && $pv !== '') {
                            $num = (float) str_replace(',', '', preg_replace('/[^\d\.\-]/', '', (string)$pv));
                            if ($num != 0.0) $poa_candidates[] = $num;
                        }
                    }
                }
            }
            if (!empty($poa_candidates)) {
                $price = min($poa_candidates);
            } else {
                if (!empty($raw['warehouse_list']) && is_array($raw['warehouse_list'])) {
                    $wprices = array();
                    foreach ($raw['warehouse_list'] as $w) {
                        if (isset($w['warehouse_price']) && $w['warehouse_price'] !== '') {
                            $wprices[] = (float) str_replace(',', '', preg_replace('/[^\d\.\-]/', '', (string)$w['warehouse_price']));
                        } elseif (isset($w['price']) && $w['price'] !== '') {
                            $wprices[] = (float) str_replace(',', '', preg_replace('/[^\d\.\-]/', '', (string)$w['price']));
                        }
                    }
                    if (!empty($wprices)) $price = min($wprices);
                }
            }
        }
        // --- end price fallback

        $images = $this->extractImages($raw);
        $saved = $this->saveAllImagesLocally($images, $product_id ? $product_id : uniqid());

        $local_images = array();
        $best_idx = 0; $best_area = 0;
        foreach ($saved as $rec) {
            if (!empty($rec['file'])) {
                $local_images[] = $rec['file'];
                $area = (isset($rec['width']) && isset($rec['height'])) ? ((int)$rec['width'] * (int)$rec['height']) : (isset($rec['size']) ? (int)$rec['size'] : 0);
                if ($area > $best_area) { $best_area = $area; $best_idx = count($local_images) - 1; }
            }
        }
        $local_images = array_values(array_unique($local_images));
        $main_image = (!empty($local_images) ? $local_images[$best_idx] : '');

        $quantity = isset($raw['stock']) ? (int)$raw['stock'] : 0;
        $status = ($quantity > 0 ? 1 : 0);
        $bg_category_id = isset($raw['category_id']) ? $raw['category_id'] : (isset($raw['cate_id']) ? $raw['cate_id'] : 59);

        return array('bg_id' => $product_id, 'name' => $title, 'description' => $description, 'price' => $price, 'images' => $images, 'main_image' => $main_image, 'local_images' => $local_images, 'quantity' => $quantity, 'status' => $status, 'bg_category_id' => $bg_category_id, 'raw' => $raw);
    }

    protected function upsertProduct($normalized) {
        $this->load->model('catalog/product');
        $bg_id = (string)$normalized['bg_id'];
        if ($bg_id === '') return 'skip';
        $existing_product_id = $this->findExistingProductByBanggoodId($bg_id);
        if ($existing_product_id) { $this->updateExistingProduct($existing_product_id, $normalized); return 'updated'; }
        else { $this->createNewProduct($normalized); return 'created'; }
    }

    protected function findExistingProductByBanggoodId($bg_id) {
        // Prefer new prefix, but allow legacy BG- products to be updated as well.
        $bg_id = (string)$bg_id;
        $model_bbc = self::PRODUCT_CODE_PREFIX . $this->db->escape($bg_id);
        $model_bg  = self::LEGACY_PRODUCT_CODE_PREFIX . $this->db->escape($bg_id);

        $query = $this->db->query(
            "SELECT product_id FROM `" . DB_PREFIX . "product`
             WHERE model IN ('" . $model_bbc . "', '" . $model_bg . "')
             ORDER BY (model = '" . $model_bbc . "') DESC
             LIMIT 1"
        );
        if ($query->num_rows) return (int)$query->row['product_id'];
        return 0;
    }

   protected function createNewProduct($normalized) {
    $this->load->model('catalog/product');
    $this->load->model('localisation/language');
    $languages = $this->model_localisation_language->getLanguages();

    // extract tables and remove from description
    $tables_to_parse = array();
    $desc_source = isset($normalized['description']) ? $normalized['description'] : '';
    $ex = $this->extractTablesFromHtml($desc_source);
    $clean_desc_text = isset($ex['clean']) ? $ex['clean'] : '';
    if (!empty($ex['tables'])) $tables_to_parse = array_merge($tables_to_parse, $ex['tables']);

    $raw_desc_source = isset($normalized['raw']['description']) ? $normalized['raw']['description'] : '';
    if ($raw_desc_source && $raw_desc_source !== $desc_source) {
        $ex2 = $this->extractTablesFromHtml($raw_desc_source);
        if (empty($clean_desc_text)) $clean_desc_text = isset($ex2['clean']) ? $ex2['clean'] : '';
        if (!empty($ex2['tables'])) $tables_to_parse = array_merge($tables_to_parse, $ex2['tables']);
    }

    // Apply bolding to label:value segments and ensure spacing after semicolons
    $clean_desc_text = $this->boldLabelValueSegments($clean_desc_text);

    // Strip ALL presentational formatting (fonts, inline styles, tags like <b>, <span>, etc.)
    // This returns safe HTML with simple <p> paragraphs.
    $clean_desc_text = $this->stripAllFormatting($clean_desc_text);

    // Download and rewrite Banggood-hosted <img> tags to local server paths (no banggood references).
    $clean_desc_text = $this->localizeDescriptionImages($clean_desc_text, $normalized['bg_id']);

    // build product_description using cleaned description (no tables)
    $product_description = array();
    foreach ($languages as $language) {
        $product_description[$language['language_id']] = array(
            'name' => $normalized['name'],
            'description' => $clean_desc_text,
            'meta_title' => $normalized['name'],
            'meta_description' => '',
            'meta_keyword' => '',
            'tag' => ''
        );
    }

    $model_code = self::PRODUCT_CODE_PREFIX . $normalized['bg_id'];
    $price = (float)$normalized['price'];
    $main_image = isset($normalized['main_image']) ? $this->toRelativeImagePath($normalized['main_image']) : '';
    $gallery_images = isset($normalized['local_images']) ? $normalized['local_images'] : array();
    $product_image = array(); $seen = array();
    foreach ($gallery_images as $img) {
        $img_rel = $this->toRelativeImagePath($img);
        if ($img_rel && !isset($seen[$img_rel]) && is_file(DIR_IMAGE . $img_rel) && filesize(DIR_IMAGE . $img_rel) > 100) { $seen[$img_rel] = true; $product_image[] = array('image' => $img_rel, 'sort_order' => 0); }
    }

    // Force all imported products into a single OpenCart category.
    // IMPORTANT: do not change categories on later updates; only set on first import.
    $category_id = (int)self::FIXED_PRODUCT_CATEGORY_ID;

    $poa_list = array();
    if (isset($normalized['raw']) && is_array($normalized['raw'])) {
        if (!empty($normalized['raw']['poa_list']) && is_array($normalized['raw']['poa_list'])) $poa_list = $normalized['raw']['poa_list'];
        elseif (!empty($normalized['raw']['option_values']) && is_array($normalized['raw']['option_values'])) $poa_list = $normalized['raw']['option_values'];
    }

    $product_options = array();
    if (!empty($poa_list)) $product_options = $this->mapAndPersistBanggoodOptions($poa_list, $normalized['bg_id'], isset($normalized['price']) ? $normalized['price'] : null);

    // Add warehouse option (Ship From) as normal option (no product_id yet)
    if (!empty($normalized['raw']['warehouse_list']) && is_array($normalized['raw']['warehouse_list'])) {
        $warehouse_option = $this->mapAndAttachBanggoodWarehouses($normalized['raw']['warehouse_list'], $normalized['bg_id'], isset($normalized['price']) ? $normalized['price'] : null, null);
        if ($warehouse_option) $product_options[] = $warehouse_option;
    }

    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $data = array(
        'model' => $model_code, 'sku' => '', 'upc' => '', 'ean' => '', 'jan' => '', 'isbn' => '', 'mpn' => '', 'location' => '',
        'quantity' => max(0, (int)$normalized['quantity']), 'stock_status_id' => $this->config->get('config_stock_status_id'),
        'image' => $main_image, 'manufacturer_id' => 0, 'shipping' => 1, 'price' => $price, 'points' => 0, 'tax_class_id' => 0,
        'date_available' => $yesterday, 'weight' => 0, 'weight_class_id' => $this->config->get('config_weight_class_id'),
        'length' => 0, 'width' => 0, 'height' => 0, 'length_class_id' => $this->config->get('config_length_class_id'),
        'subtract' => 1, 'minimum' => 1, 'sort_order' => 1, 'status' => 1,
        // Assign to fixed category on first import only
        'product_category' => array((int)$category_id),
        'product_description' => $product_description, 'product_store' => array(0), 'product_image' => $product_image,
        'product_filter' => array(), 'product_download' => array(), 'related' => array(), 'option' => $product_options
    );

    $product_id = $this->model_catalog_product->addProduct($data);

    // Force update to ensure status and date_available are set
    if ($product_id) {
        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET `status` = 1, `date_available` = '" . $this->db->escape($yesterday) . "' WHERE product_id = '" . (int)$product_id . "'");
        // Best-effort: ensure category link exists even if theme/custom addProduct ignores product_category.
        try {
            $this->db->query(
                "INSERT IGNORE INTO `" . DB_PREFIX . "product_to_category` (product_id, category_id)
                 VALUES (" . (int)$product_id . ", " . (int)$category_id . ")"
            );
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    // Attach options (insert-only) and apply POA quantities / warehouse pricing safely
    if (!empty($product_options) && $product_id) {
        try { $this->attachProductOptions((int)$product_id, $product_options); } catch (Exception $e) { error_log('attachProductOptions error (create): ' . $e->getMessage()); }
        if (!empty($poa_list)) {
            try { $this->applyPoaQuantitiesToProduct((int)$product_id, $poa_list, $normalized['bg_id']); } catch (Exception $e) { error_log('applyPoaQuantitiesToProduct error (create): ' . $e->getMessage()); }
        }
        if (!empty($normalized['raw']['warehouse_list']) && is_array($normalized['raw']['warehouse_list'])) {
            try { $this->mapAndAttachBanggoodWarehouses($normalized['raw']['warehouse_list'], $normalized['bg_id'], isset($normalized['price']) ? $normalized['price'] : null, (int)$product_id); } catch (Exception $e) { error_log('mapAndAttachBanggoodWarehouses error (create): ' . $e->getMessage()); }
            try {
                $preferred_wh = $this->config->get('module_banggood_import_preferred_warehouse');
                $this->applyWarehousePricingToProduct((int)$product_id, $normalized['bg_id'], $preferred_wh ?: null);
            } catch (Exception $e) { error_log('applyWarehousePricingToProduct error (create): ' . $e->getMessage()); }
        }
    }

    // Parse extracted tables into attributes/custom tabs
    if (!empty($tables_to_parse) && !empty($product_id)) {
        foreach ($tables_to_parse as $table_html) {
            try { $this->parseDescriptionTableToAttributes($table_html, $normalized['bg_id'], (int)$product_id); } catch (Exception $e) { error_log('parseDescriptionTableToAttributes error (create): ' . $e->getMessage()); }
        }
    }

    // Apply stocks and ship-from statuses
    try { $this->applyStocksToProduct($normalized['bg_id'], (int)$product_id, $this->getBanggoodConfig()); } catch (Exception $e) { error_log('applyStocksToProduct error (create): ' . $e->getMessage()); }
    try { $this->syncShipFromStatusesForProduct($normalized['bg_id'], (int)$product_id); } catch (Exception $e) { error_log('syncShipFromStatusesForProduct error (create): ' . $e->getMessage()); }

    // Generate image cache for product images
    try {
        $imgs = array();
        if ($main_image) $imgs[] = $main_image;
        if (!empty($product_image)) {
            foreach ($product_image as $pi) if (is_array($pi) && isset($pi['image'])) $imgs[] = $pi['image'];
        }
        $this->generateImageCacheForImages($imgs);
    } catch (Exception $e) {
        error_log('generateImageCacheForImages error (create): ' . $e->getMessage());
    }

    return $product_id;
}

    /**
     * Safe, non-destructive updateExistingProduct
     * - strips tables out of description before saving (they're parsed into attributes/custom tabs)
     * - bolds label:value segments and enforces space after semicolons
     * - updates product row and product_description without deleting option/custom-tab/poip tables
     */
   protected function updateExistingProduct($product_id, $normalized) {
    $product_id = (int)$product_id;
    if (!$product_id) return;

    $this->load->model('localisation/language');
    $languages = $this->model_localisation_language->getLanguages();

    $this->load->model('catalog/product');
    $product_info = $this->model_catalog_product->getProduct($product_id);
    if (!$product_info) return;

    // --- Extract and remove tables from description (we keep the cleaned text but won't overwrite DB) ---
    $raw_desc = '';
    if (!empty($normalized['description'])) {
        $raw_desc = $normalized['description'];
    } elseif (!empty($normalized['raw']['description'])) {
        $raw_desc = $normalized['raw']['description'];
    }
    $ex = $this->extractTablesFromHtml($raw_desc);
    $clean_desc_for_save = isset($ex['clean']) ? $ex['clean'] : '';
    $extracted_tables = isset($ex['tables']) ? $ex['tables'] : array();

    // Log that we intentionally skip updating the product description on updates
    try {
        $log_bg_id = isset($normalized['bg_id']) ? $normalized['bg_id'] : ('update-' . $product_id);
        $this->writeDebugLog($log_bg_id, ['notice' => 'description_update_skipped_on_existing_product', 'clean_desc_excerpt' => substr(trim($clean_desc_for_save), 0, 100)]);
    } catch (Exception $e) {
        error_log('writeDebugLog error (update description skipped): ' . $e->getMessage());
    }

    // --- Determine base price: normalized -> compute from maps -> existing DB price ---
    $price = null;
    if (isset($normalized['price']) && $normalized['price'] !== '' && $normalized['price'] !== null) {
        $price = (float)$normalized['price'];
    }
    if (empty($price) || $price <= 0) {
        $bg_id = isset($normalized['bg_id']) ? $normalized['bg_id'] : '';
        if (!empty($bg_id) && method_exists($this, 'computeBasePriceFromMaps')) {
            try {
                $computed = $this->computeBasePriceFromMaps($bg_id);
                if ($computed !== null && $computed > 0) $price = (float)$computed;
            } catch (Exception $e) {
                error_log('computeBasePriceFromMaps error: ' . $e->getMessage());
            }
        }
    }
    if (empty($price) || $price <= 0) $price = (float)$product_info['price'];

    // Other fields
    $model_code = self::PRODUCT_CODE_PREFIX . $this->db->escape(isset($normalized['bg_id']) ? $normalized['bg_id'] : '');
    $main_image = isset($normalized['main_image']) ? $this->toRelativeImagePath($normalized['main_image']) : $product_info['image'];
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // Update product table, but avoid overwriting quantity/stock_status_id here.
    // We'll let applyStocksToProduct compute and persist the authoritative stock values when available.
    $this->db->query(
        "UPDATE `" . DB_PREFIX . "product` SET
            `model` = '" . $this->db->escape($model_code) . "',
            `sku` = '" . $this->db->escape($product_info['sku']) . "',
            `upc` = '" . $this->db->escape($product_info['upc']) . "',
            `ean` = '" . $this->db->escape($product_info['ean']) . "',
            `jan` = '" . $this->db->escape($product_info['jan']) . "',
            `isbn` = '" . $this->db->escape($product_info['isbn']) . "',
            `mpn` = '" . $this->db->escape($product_info['mpn']) . "',
            `location` = '" . $this->db->escape($product_info['location']) . "',
            `image` = '" . $this->db->escape($main_image) . "',
            `manufacturer_id` = '" . (int)$product_info['manufacturer_id'] . "',
            `shipping` = '" . (int)$product_info['shipping'] . "',
            `price` = '" . (float)$price . "',
            `points` = '" . (int)$product_info['points'] . "',
            `tax_class_id` = '" . (int)$product_info['tax_class_id'] . "',
            `date_available` = '" . $this->db->escape($yesterday) . "',
            `weight` = '" . (float)$product_info['weight'] . "',
            `weight_class_id` = '" . (int)$product_info['weight_class_id'] . "',
            `length` = '" . (float)$product_info['length'] . "',
            `width` = '" . (float)$product_info['width'] . "',
            `height` = '" . (float)$product_info['height'] . "',
            `length_class_id` = '" . (int)$product_info['length_class_id'] . "',
            `subtract` = '" . (int)$product_info['subtract'] . "',
            `minimum` = '" . (int)$product_info['minimum'] . "',
            `sort_order` = '" . (int)$product_info['sort_order'] . "',
            `status` = 1
         WHERE product_id = " . (int)$product_id
    );

    // NOTE: intentionally do NOT update product_description here (we only set description on first import)

    // Parse extracted tables into attributes/custom tabs (non-destructive)
    if (!empty($extracted_tables)) {
        foreach ($extracted_tables as $table_html) {
            try {
                $this->parseDescriptionTableToAttributes($table_html, isset($normalized['bg_id']) ? $normalized['bg_id'] : '', (int)$product_id);
            } catch (Exception $e) {
                error_log('parseDescriptionTableToAttributes error (update): ' . $e->getMessage());
            }
        }
    }

    // Preserve existing product_image rows. Add any new images (never delete).
    $gallery_images = isset($normalized['local_images']) ? $normalized['local_images'] : array();
    if (!empty($main_image) && !in_array($main_image, $gallery_images)) array_unshift($gallery_images, $main_image);
    foreach ($gallery_images as $img) {
        $img_rel = $this->toRelativeImagePath($img);
        if (!$img_rel) continue;
        $chk = $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "product_image` WHERE product_id = " . (int)$product_id . " AND image = '" . $this->db->escape($img_rel) . "' LIMIT 1");
        if ($chk->num_rows) continue;
        $this->db->query("INSERT INTO `" . DB_PREFIX . "product_image` SET product_id = " . (int)$product_id . ", image = '" . $this->db->escape($img_rel) . "', sort_order = 0");
    }

    // Regenerate image cache for any newly added images (non-destructive)
    try {
        $imgs = array();
        if ($main_image) $imgs[] = $main_image;
        foreach ($gallery_images as $gi) if (is_string($gi)) $imgs[] = $this->toRelativeImagePath($gi);
        $this->generateImageCacheForImages($imgs);
    } catch (Exception $e) {
        error_log('generateImageCacheForImages error (update): ' . $e->getMessage());
    }

    // NEW: Refresh stocks & ship-from statuses for this product based on Banggood data.
    // applyStocksToProduct will update product.quantity, status, stock_status_id,
    // and create/update product_option_value rows. Capture diagnostics and enforce final values.
    $diagnostics = array();
    try {
        if (!empty($normalized['bg_id'])) {
            $diagnostics = $this->applyStocksToProduct($normalized['bg_id'], (int)$product_id, $this->getBanggoodConfig());
        }
    } catch (Exception $e) {
        error_log('applyStocksToProduct error (update): ' . $e->getMessage());
    }

    // If applyStocksToProduct returned final_product_qty, enforce those values on the product row.
    if (!empty($diagnostics) && isset($diagnostics['final_product_qty']) && is_array($diagnostics['final_product_qty'])) {
        $final = $diagnostics['final_product_qty'];
        $final_qty = isset($final['quantity']) ? (int)$final['quantity'] : null;
        $final_status = isset($final['status']) ? (int)$final['status'] : null;
        $final_stock_status_id = isset($final['stock_status_id']) ? (int)$final['stock_status_id'] : null;

        $updateParts = array();
        if ($final_qty !== null) $updateParts[] = "`quantity` = '" . (int)$final_qty . "'";
        if ($final_status !== null) $updateParts[] = "`status` = '" . (int)$final_status . "'";
        if ($final_stock_status_id !== null) $updateParts[] = "`stock_status_id` = '" . (int)$final_stock_status_id . "'";

        if (!empty($updateParts)) {
            // Also ensure date_available set to yesterday (keeps product visible)
            $updateParts[] = "`date_available` = '" . $this->db->escape($yesterday) . "'";
            $this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . implode(', ', $updateParts) . " WHERE product_id = " . (int)$product_id);
        }
    } else {
        // Fallback: if there's a normalized quantity provided, apply it (non-destructive)
        if (isset($normalized['quantity']) && $normalized['quantity'] !== '') {
            $norm_qty = (int)$normalized['quantity'];
            // update product quantity but preserve non-zero unless explicitly allowed by config
            if ($this->allowPovOverwrite()) {
                $this->db->query("UPDATE `" . DB_PREFIX . "product` SET `quantity` = '" . (int)$norm_qty . "', `date_available` = '" . $this->db->escape($yesterday) . "' WHERE product_id = " . (int)$product_id);
            } else {
                // Update only if existing product quantity is zero
                $this->db->query("UPDATE `" . DB_PREFIX . "product` SET `quantity` = '" . (int)$norm_qty . "', `date_available` = '" . $this->db->escape($yesterday) . "' WHERE product_id = " . (int)$product_id . " AND `quantity` = 0");
            }
        }
    }

    // Also sync Ship From option statuses (banggood_status) for product POV rows
    try {
        if (!empty($normalized['bg_id'])) {
            $this->syncShipFromStatusesForProduct($normalized['bg_id'], (int)$product_id);
        }
    } catch (Exception $e) {
        error_log('syncShipFromStatusesForProduct error (update): ' . $e->getMessage());
    }

    // BEST-EFFORT: ensure product_variant rows exist after update as well
    try {
        $bg = isset($normalized['bg_id']) ? $normalized['bg_id'] : '';
        if (!empty($bg) && !empty($product_id)) {
            try {
                $stocks = $this->getStocksForProduct($this->getBanggoodConfig(), $bg);
            } catch (Exception $x) {
                $stocks = array();
            }
            try {
                $this->upsertProductVariantsFromBgPoa($bg, (int)$product_id, $stocks);
            } catch (Exception $e) {
                error_log('Banggood: upsertProductVariantsFromBgPoa (post-update) failed: ' . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log('Banggood: upsertProductVariantsFromBgPoa (post-update outer) failed: ' . $e->getMessage());
    }
}

    /* -------------------------
       Attach product options (create product_option and product_option_value rows)
       ------------------------- */
    protected function attachProductOptions($product_id, array $product_options) {
        if (empty($product_id) || empty($product_options)) return;
        foreach ($product_options as $po) {
            $option_id = isset($po['option_id']) ? (int)$po['option_id'] : 0;
            $value     = isset($po['value']) ? $po['value'] : '';
            $required  = isset($po['required']) ? (int)$po['required'] : 1;
            if (!$option_id) continue;
            $check = $this->db->query("SELECT product_option_id FROM `" . DB_PREFIX . "product_option` WHERE product_id = '" . (int)$product_id . "' AND option_id = '" . (int)$option_id . "' LIMIT 1");
            if ($check->num_rows) $product_option_id = (int)$check->row['product_option_id'];
            else { $this->db->query("INSERT INTO `" . DB_PREFIX . "product_option` SET product_id = '" . (int)$product_id . "', option_id = '" . (int)$option_id . "', `value` = '" . $this->db->escape($value) . "', required = '" . (int)$required . "'"); $product_option_id = $this->db->getLastId(); }

            if (!empty($po['product_option_value']) && is_array($po['product_option_value'])) {
                foreach ($po['product_option_value'] as $pov) {
                    $option_value_id = isset($pov['option_value_id']) ? (int)$pov['option_value_id'] : 0;
                    if (!$option_value_id) continue;
                    $chk2 = $this->db->query("SELECT product_option_value_id FROM `" . DB_PREFIX . "product_option_value` WHERE product_id = '" . (int)$product_id . "' AND option_value_id = '" . (int)$option_value_id . "' LIMIT 1");
                    if ($chk2->num_rows) continue;
                    $quantity = isset($pov['quantity']) ? (int)$pov['quantity'] : 0;
                    $subtract = isset($pov['subtract']) ? (int)$pov['subtract'] : 1;
                    $price = isset($pov['price']) ? (float)$pov['price'] : 0.00;
                    $prefix = isset($pov['price_prefix']) ? $this->db->escape($pov['price_prefix']) : '+';
                    $points = isset($pov['points']) ? (int)$pov['points'] : 0;
                    $points_prefix = isset($pov['points_prefix']) ? $this->db->escape($pov['points_prefix']) : '+';
                    $weight = isset($pov['weight']) ? (float)$pov['weight'] : 0;
                    $weight_prefix = isset($pov['weight_prefix']) ? $this->db->escape($pov['weight_prefix']) : '+';
                    $this->db->query(
                        "INSERT INTO `" . DB_PREFIX . "product_option_value` SET product_option_id = '" . (int)$product_option_id . "', product_id = '" . (int)$product_id . "', option_id = '" . (int)$option_id . "', option_value_id = '" . (int)$option_value_id . "', quantity = '" . (int)$quantity . "', subtract = '" . (int)$subtract . "', price = '" . (float)$price . "', price_prefix = '" . $this->db->escape($prefix) . "', points = '" . (int)$points . "', points_prefix = '" . $this->db->escape($points_prefix) . "', weight = '" . (float)$weight . "', weight_prefix = '" . $this->db->escape($weight_prefix) . "'"
                    );
                }
            }
        }
    }

    /* -------------------------
       Image helpers
       ------------------------- */
    protected function saveAllImagesLocally($images, $bg_id) {
        $saved = array(); $seen_hashes = array(); $seen_urls = array();
        $index = 1;

        // base folder per product: image/catalog/BBC-<bg_id>/
        $folder = 'catalog/' . self::PRODUCT_CODE_PREFIX . preg_replace('/[^0-9A-Za-z_.-]/', '_', (string)$bg_id) . '/';
        $img_dir = rtrim(DIR_IMAGE, '/\\') . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($img_dir)) @mkdir($img_dir, 0777, true);

        foreach ($images as $orig_url) {
            if (!$orig_url || strpos($orig_url, 'http') !== 0) continue;
            $norm_url = preg_replace('/(\?.*)$/', '', $orig_url);
            if (isset($seen_urls[$norm_url])) continue;
            $seen_urls[$norm_url] = true;
            $try_urls = array($orig_url);
            if (strpos($orig_url, '/thumb/') !== false) { $try_urls[] = str_replace('/thumb/', '/images/', $orig_url); $try_urls[] = str_replace('/thumb/', '/large/', $orig_url); }
            if (strpos($orig_url, '/thumb/view/') !== false) { $try_urls[] = str_replace('/thumb/view/', '/images/', $orig_url); }
            $try_urls[] = preg_replace('/(\?.*)$/', '', $orig_url);
            $best = null;
            foreach ($try_urls as $tu) {
                if (!$tu) continue;
                $img_data = @file_get_contents($tu);
                if ($img_data === false || strlen($img_data) < 100) continue;
                $info = @getimagesizefromstring($img_data);
                $width = isset($info[0]) ? (int)$info[0] : 0;
                $height = isset($info[1]) ? (int)$info[1] : 0;
                $size = strlen($img_data);
                $hash = md5($img_data);
                if (isset($seen_hashes[$hash])) { $best = null; break 2; }
                if ($best === null || ($width * $height) > ($best['width'] * $best['height']) || ($size > $best['size'] && ($best['width'] * $best['height'] == 0))) {
                    $best = array('data' => $img_data, 'width' => $width, 'height' => $height, 'size' => $size, 'hash' => $hash);
                }
            }
            if ($best === null) continue;

            $img_file = $folder . self::PRODUCT_CODE_PREFIX . "{$bg_id}-{$index}.jpg";
            $local_path = rtrim(DIR_IMAGE, '/\\') . DIRECTORY_SEPARATOR . $img_file;
            $ok = $this->writeJpegFromData($best['data'], $local_path);
            if (!$ok) {
                @file_put_contents($local_path, $best['data']);
            }
            if (is_file($local_path) && filesize($local_path) > 100) {
                $info2 = @getimagesize($local_path);
                $w2 = isset($info2[0]) ? (int)$info2[0] : $best['width'];
                $h2 = isset($info2[1]) ? (int)$info2[1] : $best['height'];
                $size2 = filesize($local_path);
                $hash2 = $best['hash'];
                $saved[] = array('file' => $this->toRelativeImagePath($img_file), 'width' => $w2, 'height' => $h2, 'size' => $size2, 'hash' => $hash2);
                $seen_hashes[$hash2] = true; $index++;
            }
        }
        $uniq = array(); $files = array();
        foreach ($saved as $rec) { if (!isset($uniq[$rec['file']])) { $uniq[$rec['file']] = true; $files[] = $rec; } }
        return $files;
    }

    /**
     * Download external <img> URLs found in product descriptions and rewrite them to local URLs.
     *
     * Saves images under: image/catalog/<PREFIX><bg_id>/desc/
     * Rewrites <img src="..."> to: image/<relative_path>
     * Only rewrites banggood-hosted images (so your site contains no banggood image references).
     */
    protected function localizeDescriptionImages($html, $bg_id) {
        if (empty($html) || !is_string($html)) return $html;
        $bg_id = (string)$bg_id;

        // Build a full base URL so <img src> in description always renders.
        // Prefer the store (catalog) URL from config; fall back to constants/server URL if needed.
        $base_url = '';
        try {
            $cfg_ssl = $this->config->get('config_ssl');
            $cfg_url = $this->config->get('config_url');
            if (is_string($cfg_ssl) && $cfg_ssl !== '') $base_url = $cfg_ssl;
            elseif (is_string($cfg_url) && $cfg_url !== '') $base_url = $cfg_url;
        } catch (\Throwable $e) {}
        if ($base_url === '') {
            if (defined('HTTPS_CATALOG') && HTTPS_CATALOG) $base_url = HTTPS_CATALOG;
            elseif (defined('HTTP_CATALOG') && HTTP_CATALOG) $base_url = HTTP_CATALOG;
            elseif (defined('HTTPS_SERVER') && HTTPS_SERVER) $base_url = HTTPS_SERVER;
            elseif (defined('HTTP_SERVER') && HTTP_SERVER) $base_url = HTTP_SERVER;
        }
        $base_url = trim((string)$base_url);
        if ($base_url !== '') {
            // If we accidentally got an admin URL, strip trailing "admin/" segment.
            $base_url = preg_replace('#/admin/?$#i', '/', $base_url);
            if (substr($base_url, -1) !== '/') $base_url .= '/';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="bg_desc_root">' . $html . '</div>');
        libxml_clear_errors();
        if (!$loaded) return $html;

        $root = $dom->getElementById('bg_desc_root');
        if (!$root) return $html;

        $folder = 'catalog/' . self::PRODUCT_CODE_PREFIX . preg_replace('/[^0-9A-Za-z_.-]/', '_', (string)$bg_id) . '/desc/';
        $img_dir = rtrim(DIR_IMAGE, '/\\') . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($img_dir)) @mkdir($img_dir, 0777, true);

        $seen = array(); // normalized url => relative file
        $idx = 1;

        // DOMNodeList is live; copy nodes first
        $nodes = array();
        foreach ($root->getElementsByTagName('img') as $n) $nodes[] = $n;

        foreach ($nodes as $img) {
            if (!$img instanceof DOMElement) continue;

            // Ensure images in description are centered (inline, non-invasive: only appends if missing)
            try {
                $curStyle = (string)$img->getAttribute('style');
                $needsCenter = (stripos($curStyle, 'margin-left') === false) && (stripos($curStyle, 'margin-right') === false) && (stripos($curStyle, 'margin:') === false);
                $needsBlock = (stripos($curStyle, 'display') === false);
                if ($needsCenter || $needsBlock) {
                    $add = '';
                    if ($needsBlock) $add .= 'display:block;';
                    if ($needsCenter) $add .= 'margin-left:auto;margin-right:auto;';
                    if ($add !== '') {
                        $newStyle = trim($curStyle);
                        if ($newStyle !== '' && substr($newStyle, -1) !== ';') $newStyle .= ';';
                        $newStyle .= $add;
                        $img->setAttribute('style', $newStyle);
                    }
                }
            } catch (\Throwable $e) {
                // best-effort; keep original description intact on any DOM/style errors
            }

            $src = trim((string)$img->getAttribute('src'));
            if ($src === '') continue;

            // protocol-relative
            if (strpos($src, '//') === 0) $src = 'https:' . $src;
            // Only handle remote http(s) images for downloading; keep other schemes intact.
            if (stripos($src, 'http://') !== 0 && stripos($src, 'https://') !== 0) continue;

            $norm = preg_replace('/(\?.*)$/', '', $src);
            if (isset($seen[$norm])) {
                $img->setAttribute('src', $base_url . 'image/' . ltrim($seen[$norm], '/'));
                continue;
            }

            $file_name = self::PRODUCT_CODE_PREFIX . preg_replace('/[^0-9A-Za-z_.-]/', '_', (string)$bg_id) . '-DESC-' . $idx . '.jpg';
            $idx++;
            $local_path = $img_dir . $file_name;

            $data = null;
            try {
                if (function_exists('curl_init')) {
                    $curl_opts = array(
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_TIMEOUT => 25,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_USERAGENT => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'OpenCart-Banggood-Client'
                    );
                    $ch = curl_init($src);
                    curl_setopt_array($ch, $curl_opts);
                    $data = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($data === false || strlen($data) < 200 || $code < 200 || $code >= 400) $data = null;
                } else {
                    // Fallback: file_get_contents (some servers don't have curl enabled)
                    $ctx = stream_context_create(array('http' => array(
                        'timeout' => 25,
                        'follow_location' => 1,
                        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'OpenCart-Banggood-Client'
                    )));
                    $tmp = @file_get_contents($src, false, $ctx);
                    if ($tmp !== false && strlen($tmp) >= 200) $data = $tmp;
                }
            } catch (\Throwable $e) {
                $data = null;
            }
            if ($data === null) continue;

            // Write as JPEG for consistency
            $ok = $this->writeJpegFromData($data, $local_path);
            if (!$ok) @file_put_contents($local_path, $data);
            if (!is_file($local_path) || filesize($local_path) < 100) continue;

            $rel = $this->toRelativeImagePath($folder . $file_name);
            if (!$rel) continue;

            $seen[$norm] = $rel;
            $img->setAttribute('src', $base_url . 'image/' . ltrim($rel, '/'));
        }

        // Return inner HTML
        $out = '';
        foreach ($root->childNodes as $child) {
            $piece = $dom->saveHTML($child);
            if ($piece) $out .= $piece;
        }
        return $out !== '' ? $out : $html;
    }

    protected function downloadOptionImage($image_url, $option_slug = 'opt', $value_id = 0, $bg_id = '') {
        if (empty($image_url) || strpos($image_url, 'http') !== 0) return '';
        // base folder: image/catalog/BBC-<bg_id>/options/  (fallback to catalog/options/ if no bg_id)
        if (!empty($bg_id)) {
            $base_folder = 'catalog/' . self::PRODUCT_CODE_PREFIX . preg_replace('/[^0-9A-Za-z_.-]/', '_', (string)$bg_id) . '/options/';
        } else {
            $base_folder = 'catalog/options/';
        }
        $img_dir = rtrim(DIR_IMAGE, '/\\') . DIRECTORY_SEPARATOR . $base_folder;
        if (!is_dir($img_dir)) @mkdir($img_dir, 0777, true);
        $safe_slug = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$option_slug);
        $file_name = 'BBC-OPT-' . ($bg_id ? preg_replace('/[^0-9A-Za-z_.-]/', '_', (string)$bg_id) . '-' : '') . $safe_slug . '-' . (int)$value_id . '.jpg';
        $local_path = $img_dir . $file_name;

        $try_urls = array($image_url);
        if (strpos($image_url, '/thumb/') !== false) { $try_urls[] = str_replace('/thumb/', '/images/', $image_url); $try_urls[] = str_replace('/thumb/', '/large/', $image_url); }
        if (strpos($image_url, '/thumb/view/') !== false) $try_urls[] = str_replace('/thumb/view/', '/images/', $image_url);
        $try_urls[] = preg_replace('/(\?.*)$/', '', $image_url);

        $curl_opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'OpenCart-Banggood-Client'
        );

        foreach ($try_urls as $tu) {
            if (!$tu) continue;
            $tries = 0;
            do {
                $tries++;
                $ch = curl_init($tu);
                curl_setopt_array($ch, $curl_opts);
                $img_data = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($img_data !== false && strlen($img_data) > 200 && $http_code >= 200 && $http_code < 400) {
                    $ok = $this->writeJpegFromData($img_data, $local_path);
                    if (!$ok) @file_put_contents($local_path, $img_data);
                    if (is_file($local_path) && filesize($local_path) > 100) {
                        @chmod($local_path, 0644);
                        return $this->toRelativeImagePath($base_folder . $file_name);
                    }
                }
                usleep(100000);
            } while ($tries < 2);
        }
        return '';
    }

    protected function toRelativeImagePath($path) {
        if (!$path) return '';
        if (preg_match('#^(catalog|image)/#i', $path)) return preg_replace('#^image/#i', '', $path);
        $dirImage = rtrim(DIR_IMAGE, '/\\') . '/';
        if (strpos($path, $dirImage) === 0) { $rel = substr($path, strlen($dirImage)); return ltrim($rel, '/\\'); }
        if (preg_match('#(catalog/.*)$#i', $path, $m)) return $m[1];
        return $path;
    }

    /* -------------------------
       Extract images from raw product data
       ------------------------- */
    protected function extractImages($raw) {
        $images = array();

        if (isset($raw['poa_list']) && is_array($raw['poa_list'])) {
            foreach ($raw['poa_list'] as $poa) {
                if (isset($poa['option_values']) && is_array($poa['option_values'])) {
                    foreach ($poa['option_values'] as $optval) {
                        foreach (array('large_image','view_image','list_grid_image','small_image','image') as $k) {
                            if (!empty($optval[$k]) && is_string($optval[$k])) { $images[] = $optval[$k]; break; }
                        }
                    }
                }
            }
        }

        if (isset($raw['image_list']) && is_array($raw['image_list'])) {
            $prefs = array('large','view','home','list_grid','grid','gallery','other_items');
            foreach ($prefs as $p) { if (!empty($raw['image_list'][$p]) && is_array($raw['image_list'][$p])) { foreach ($raw['image_list'][$p] as $img) if (is_string($img) && $img) $images[] = $img; } }
            foreach ($raw['image_list'] as $group) { if (is_array($group)) { foreach ($group as $img) if (is_string($img) && $img) $images[] = $img; } }
        }

        if (isset($raw['images']) && is_array($raw['images'])) { foreach ($raw['images'] as $img) if (is_string($img) && $img) $images[] = $img; }
        if (isset($raw['gallery']) && is_array($raw['gallery'])) { foreach ($raw['gallery'] as $img) if (is_string($img) && $img) $images[] = $img; }
        if (isset($raw['image']) && is_string($raw['image'])) $images[] = $raw['image'];

        if (isset($raw['description']) && is_string($raw['description'])) {
            if (preg_match_all('/<img\s+[^>]*src=["\']([^"\'>]+)["\']/i', $raw['description'], $matches)) {
                foreach ($matches[1] as $imgurl) if ($imgurl) $images[] = $imgurl;
            }
        }

        $out = array(); $seen = array();
        foreach ($images as $u) {
            $u = trim($u); if ($u === '') continue;
            $norm = preg_replace('/(\?.*)$/', '', $u);
            if (empty($seen[$norm])) { $seen[$norm] = true; $out[] = $u; }
        }
        return array_values($out);
    }

    /* -------------------------
       Options mapping & persistence (UPDATED: computes and persists poa_price)
       ------------------------- */
    protected function mapAndPersistBanggoodOptions(array $poa_list, $bg_id, $bg_base_price = null) {
        $this->load->model('localisation/language');
        $languages = $this->model_localisation_language->getLanguages();

        $result_product_options = array();

        foreach ($poa_list as $group) {
            $option_name = isset($group['option_name']) ? $group['option_name'] : (isset($group['poa_name']) ? $group['poa_name'] : (isset($group['name']) ? $group['name'] : ''));
            $option_name = $this->normalizeImportedOptionName($option_name);
            if (!$option_name) continue;

            $option_type = 'radio';
            $option_id = $this->getOptionIdByName($option_name);
            if (!$option_id) $option_id = $this->addOption($option_name, $option_type);
            else $this->db->query("UPDATE `" . DB_PREFIX . "option` SET `type` = 'radio' WHERE option_id = '" . (int)$option_id . "'");

            $is_size_option = (stripos($option_name, 'size') !== false) || (stripos($option_name, 'size') === 0);

            $product_option_values = array();

            // If this is a size option, precompute sort indices for the values
            $size_sort_seen = array();
            $next_non_size_sort = 100; // non-size values will get sort order starting at 100 to keep sizes first

            if (!empty($group['option_values']) && is_array($group['option_values'])) {
                foreach ($group['option_values'] as $val) {
                    $value_name = isset($val['poa_name']) ? $val['poa_name'] : (isset($val['name']) ? $val['name'] : (isset($val['poa_value']) ? $val['poa_value'] : ''));
                    if ($value_name === '') continue;

                    // compute sort order for sizes
                    $sort_order = 0;
                    if ($is_size_option) {
                        $so = $this->computeSizeSortOrder($value_name);
                        if ($so !== null) {
                            $sort_order = (int)$so;
                            // ensure unique sort numbers in case duplicates, shift if already used
                            while (in_array($sort_order, $size_sort_seen)) $sort_order++;
                            $size_sort_seen[] = $sort_order;
                        } else {
                            // assign after the known sizes
                            while (in_array($next_non_size_sort, $size_sort_seen)) $next_non_size_sort++;
                            $sort_order = $next_non_size_sort++;
                            $size_sort_seen[] = $sort_order;
                        }
                    } else {
                        // non-size options keep default sort 0
                        $sort_order = 0;
                    }

                    // Raw price coming from API
                    $raw_price = isset($val['poa_price']) ? $val['poa_price'] : (isset($val['price']) ? $val['price'] : null);
                    $price_num = null;
                    $price_prefix = '+';

                    if ($raw_price !== null && $raw_price !== '') {
                        $rp = trim((string)$raw_price);
                        if (preg_match('/^([+\-])\s*([0-9\.,]+)/', $rp, $m)) {
                            $price_prefix = ($m[1] === '-') ? '-' : '+';
                            $price_num = (float)str_replace(',', '', $m[2]);
                        } elseif (is_numeric(str_replace(',', '', $rp))) {
                            // Per Banggood docs: final price = warehouse_price + sum(poa_price for selected options)
                            // So poa_price is treated as an *additive modifier*, not an absolute price.
                            $raw_num = (float)str_replace(',', '', $rp);
                            $price_prefix = ($raw_num < 0) ? '-' : '+';
                            $price_num = abs($raw_num);
                        } else {
                            if (preg_match('/([0-9\.,]+)/', $rp, $m2)) {
                                $price_num = (float)str_replace(',', '', $m2[1]);
                                $price_prefix = '+';
                            }
                        }
                    }

                    if ($price_num === null) $price_num = 0.00;

                    $image_url = '';
                    if (!empty($val['large_image'])) $image_url = $val['large_image'];
                    elseif (!empty($val['view_image'])) $image_url = $val['view_image'];
                    elseif (!empty($val['list_grid_image'])) $image_url = $val['list_grid_image'];
                    elseif (!empty($val['small_image'])) $image_url = $val['small_image'];
                    elseif (!empty($val['image'])) $image_url = $val['image'];

                    $option_value_id = $this->getOptionValueIdByName($option_id, $value_name);
                    if (!$option_value_id) {
                        $option_value_id = $this->addOptionValue($option_id, $value_name, $image_url, $sort_order, $bg_id);
                    } else {
                        // Ensure sort_order is updated for existing entries
                        $this->db->query("UPDATE `" . DB_PREFIX . "option_value` SET sort_order = '" . (int)$sort_order . "' WHERE option_value_id = '" . (int)$option_value_id . "'");
                        $this->ensureOptionValueImage($option_value_id, $image_url, $bg_id, $option_name, $value_name);
                    }

                    $poa_id_raw = '';
                    if (isset($val['poa_id'])) $poa_id_raw = (string)$val['poa_id'];
                    elseif (isset($val['poaId'])) $poa_id_raw = (string)$val['poaId'];
                    if ($poa_id_raw !== '') {
                        $this->ensureBgPoaMapTableExists();
                        $poa_parts = $this->splitPoaIds($poa_id_raw);
                        $tbl = DB_PREFIX . "bg_poa_map";
                        $allow_map_writes = (bool)$this->config->get('module_banggood_import_allow_map_writes');
                        foreach ($poa_parts as $poa_part) {
                            if ($allow_map_writes) {
                                $this->db->query(
                                    "INSERT INTO `" . $tbl . "` (bg_id, poa_id, option_value_id, poa_price) VALUES ('" . $this->db->escape($bg_id) . "', '" . $this->db->escape($poa_part) . "', '" . (int)$option_value_id . "', '" . (float)$price_num . "')
                                     ON DUPLICATE KEY UPDATE option_value_id = '" . (int)$option_value_id . "', poa_price = '" . (float)$price_num . "'"
                                );
                            }
                        }
                    }

                    $product_option_values[] = array(
                        'product_option_value_id' => '',
                        'option_value_id'         => (int)$option_value_id,
                        'quantity'                => (int)$this->extractPoaQuantity(is_array($val) ? $val : array()),
                        'subtract'                => 1,
                        'price'                   => (float)$price_num,
                        'price_prefix'            => $price_prefix,
                        'points'                  => 0,
                        'points_prefix'           => '+',
                        'weight'                  => 0,
                        'weight_prefix'           => '+',
                    );
                }
            }

            $result_product_options[] = array(
                'product_option_id'    => '',
                'product_option_value' => $product_option_values,
                'option_id'            => (int)$option_id,
                'name'                 => $option_name,
                'type'                 => $option_type,
                'value'                => '',
                'required'             => true,
            );
        }

        return $result_product_options;
    }

    /* -------------------------
       New: Map warehouses as product option "Ship From" and attach/update product option rows
       ------------------------- */
    protected function mapAndAttachBanggoodWarehouses(array $warehouse_list, $bg_id, $bg_base_price = null, $product_id = null) {
        if (empty($warehouse_list) || !is_array($warehouse_list)) return null;
        $warehouse_option_name = 'Ship From';

        // find or create the global option
        $option_id = $this->getOptionIdByName($warehouse_option_name);
        if (!$option_id) {
            $option_id = $this->addOption($warehouse_option_name, 'radio');
        } else {
            // ensure type is radio
            $this->db->query("UPDATE `" . DB_PREFIX . "option` SET `type` = 'radio' WHERE option_id = '" . (int)$option_id . "'");
        }

        $product_option_values = array();

        foreach ($warehouse_list as $w) {
            // Determine a label and key for the warehouse
            $wh_key = '';
            if (!empty($w['warehouse'])) $wh_key = (string)$w['warehouse'];
            elseif (!empty($w['site'])) $wh_key = (string)$w['site'];
            elseif (!empty($w['country'])) $wh_key = (string)$w['country'];

            $wh_label = '';
            if (!empty($w['warehouse_name'])) $wh_label = (string)$w['warehouse_name'];
            elseif (!empty($w['name'])) $wh_label = (string)$w['name'];
            elseif (!empty($wh_key)) $wh_label = $wh_key;
            else $wh_label = 'Warehouse';

            // warehouse price if present
            $warehouse_price = null;
            if (isset($w['warehouse_price']) && $w['warehouse_price'] !== '') $warehouse_price = (float)$w['warehouse_price'];

            // compute modifier relative to bg base price if available
            $modifier = 0.00;
            $prefix = '+';
            if ($warehouse_price !== null && $bg_base_price !== null) {
                $delta = round((float)$warehouse_price - (float)$bg_base_price, 2);
                $prefix = ($delta < 0) ? '-' : '+';
                $modifier = abs($delta);
            } elseif ($warehouse_price !== null && $bg_base_price === null) {
                $modifier = (float)$warehouse_price;
                $prefix = '+';
            } else {
                $modifier = 0.00;
                $prefix = '+';
            }

            // find or create the option value under this option
            $option_value_id = $this->getOptionValueIdByName($option_id, $wh_label);
            if (!$option_value_id) {
                $option_value_id = $this->addOptionValue($option_id, $wh_label, '');
            }

            // determine total stock for this warehouse by summing stock rows returned by getStocksForProduct
            $total_wh_stock = 0;
            try {
                $stocks_for_product = $this->getStocksForProduct($this->getBanggoodConfig(), $bg_id);
                if (is_array($stocks_for_product)) {
                    $cmp_target1 = strtoupper(trim((string)$wh_key));
                    $cmp_target2 = strtoupper(trim((string)$wh_label));
                    foreach ($stocks_for_product as $srow) {
                        $s_wh = isset($srow['warehouse']) ? (string)$srow['warehouse'] : '';
                        $s_wh_name = isset($srow['warehouse_name']) ? (string)$srow['warehouse_name'] : '';
                        $cmp1 = strtoupper(trim($s_wh));
                        $cmp2 = strtoupper(trim($s_wh_name));
                        if ($cmp1 === $cmp_target1 || $cmp2 === $cmp_target1 || $cmp1 === $cmp_target2 || $cmp2 === $cmp_target2) {
                            $total_wh_stock += (int)(isset($srow['stock']) ? $srow['stock'] : 0);
                        }
                    }
                }
            } catch (Exception $e) {
                // best-effort: leave total_wh_stock at 0 if stocks cannot be fetched
                $total_wh_stock = 0;
            }

            // Build product_option_value structure to return
            $product_option_values[] = array(
                'product_option_value_id' => '',
                'option_value_id'         => (int)$option_value_id,
                'quantity'                => (int)$total_wh_stock,
                'subtract'                => 1,
                'price'                   => (float)$modifier,
                'price_prefix'            => $prefix,
                'points'                  => 0,
                'points_prefix'           => '+',
                'weight'                  => 0,
                'weight_prefix'           => '+',
            );

            // If product_id provided ensure product_option and product_option_value exist and update price/quantity
            if (!empty($product_id)) {
                // ensure product_option exists
                $qpo = $this->db->query("SELECT product_option_id FROM `" . DB_PREFIX . "product_option` WHERE product_id = '" . (int)$product_id . "' AND option_id = '" . (int)$option_id . "' LIMIT 1");
                if ($qpo->num_rows) {
                    $product_option_id = (int)$qpo->row['product_option_id'];
                } else {
                    $this->db->query("INSERT INTO `" . DB_PREFIX . "product_option` (product_id, option_id, `value`, required) VALUES ('" . (int)$product_id . "', '" . (int)$option_id . "', '', 1)");
                    $product_option_id = $this->db->getLastId();
                }

                // If POV overwrite allowed we will update/insert POV rows; otherwise we avoid changing existing POVs (see below)
                $qpov = $this->db->query("SELECT product_option_value_id, quantity FROM `" . DB_PREFIX . "product_option_value` WHERE product_id = '" . (int)$product_id . "' AND option_value_id = '" . (int)$option_value_id . "' LIMIT 1");
                if ($qpov->num_rows) {
                    $pov_id = (int)$qpov->row['product_option_value_id'];
                    $existing_qty = isset($qpov->row['quantity']) ? (int)$qpov->row['quantity'] : 0;
                    if ($this->allowPovOverwrite() || $existing_qty === 0) {
                        // update price, prefix and quantity
                        $this->db->query(
                            "UPDATE `" . DB_PREFIX . "product_option_value` SET price = '" . (float)$modifier . "', price_prefix = '" . $this->db->escape($prefix) . "', quantity = '" . (int)$total_wh_stock . "' WHERE product_option_value_id = '" . $pov_id . "'"
                        );
                    }
                } else {
                    // insert with quantity = total warehouse stock
                    $this->db->query(
                        "INSERT INTO `" . DB_PREFIX . "product_option_value` (product_option_id, product_id, option_id, option_value_id, quantity, subtract, price, price_prefix, points, points_prefix, weight, weight_prefix) VALUES ('" . (int)$product_option_id . "','" . (int)$product_id . "','" . (int)$option_id . "','" . (int)$option_value_id . "', " . (int)$total_wh_stock . ", 1, '" . (float)$modifier . "', '" . $this->db->escape($prefix) . "', 0, '+', 0, '+')"
                    );
                    $pov_id = (int)$this->db->getLastId();
                }

                // compute banggood_status for this bg_id + warehouse_key (use wh_key value)
                $q = $this->db->query(
                    "SELECT COALESCE(NULLIF(MIN(NULLIF(product_stock_msg, '')),''), CASE WHEN SUM(product_stock)>0 THEN 'AVAILABLE' ELSE 'SOLD_OUT' END) AS computed
                     FROM `" . DB_PREFIX . "bg_poa_warehouse_map`
                     WHERE bg_id = '" . $this->db->escape($bg_id) . "' AND warehouse_key = '" . $this->db->escape($wh_key) . "'"
                );

                $computed = '';
                if ($q->num_rows && !empty($q->row['computed'])) {
                    $computed = $q->row['computed'];
                } else {
                    // As a fallback compute total product_stock for this warehouse_key
                    $qtot = $this->db->query(
                        "SELECT COALESCE(SUM(product_stock),0) AS tot
                         FROM `" . DB_PREFIX . "bg_poa_warehouse_map`
                         WHERE bg_id = '" . $this->db->escape($bg_id) . "' AND warehouse_key = '" . $this->db->escape($wh_key) . "'"
                    );
                    $tot = ($qtot && isset($qtot->row['tot'])) ? (int)$qtot->row['tot'] : 0;
                    $computed = ($tot > 0) ? 'AVAILABLE' : 'SOLD_OUT';
                }

                // Persist computed status on the product_option_value row we just created/updated
                if (!empty($pov_id)) {
                    $this->db->query(
                        "UPDATE `" . DB_PREFIX . "product_option_value`
                         SET banggood_status = '" . $this->db->escape($computed) . "'
                         WHERE product_option_value_id = '" . (int)$pov_id . "'"
                    );
                }
                // ---- END patch ----
            }
        }

        // return structure to append to $product_options
        return array(
            'product_option_id'    => '',
            'product_option_value' => $product_option_values,
            'option_id'            => (int)$option_id,
            'name'                 => $warehouse_option_name,
            'type'                 => 'radio',
            'value'                => '',
            'required'             => true,
        );
    }

    /* -------------------------
       Apply warehouse pricing and stock to product and options
       ------------------------- */
    protected function applyWarehousePricingToProduct($product_id, $bg_id, $warehouse_key = null) {
        if (empty($product_id) || empty($bg_id)) return false;

        // prefer configured warehouse if none provided
        if ($warehouse_key === null) {
            $cfg_wh = $this->config->get('module_banggood_import_preferred_warehouse');
            if (!empty($cfg_wh)) $warehouse_key = $cfg_wh;
        }

        // find available warehouses for this bg_id
        $rows = $this->db->query("SELECT DISTINCT warehouse_key FROM `" . DB_PREFIX . "bg_poa_warehouse_map` WHERE bg_id = '" . $this->db->escape($bg_id) . "'")->rows;
        if (empty($warehouse_key)) {
            if (empty($rows)) return false;
            $warehouse_key = $rows[0]['warehouse_key'];
        }

        // load mappings for this bg_id & warehouse
        $map_q = $this->db->query(
            "SELECT poa_id, product_price, price_modifier, product_stock
             FROM `" . DB_PREFIX . "bg_poa_warehouse_map`
             WHERE bg_id = '" . $this->db->escape($bg_id) . "' AND warehouse_key = '" . $this->db->escape($warehouse_key) . "'"
        );
        if (empty($map_q->rows)) return false;

        // compute warehouse base (use consistent value)
        $warehouse_base = null;
        foreach ($map_q->rows as $r) {
            $wb = (float)$r['product_price'] - (float)$r['price_modifier'];
            $warehouse_base = ($warehouse_base === null) ? $wb : min($warehouse_base, $wb);
        }
        if ($warehouse_base === null) return false;

        // Update product base price
        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET price = '" . (float)$warehouse_base . "' WHERE product_id = '" . (int)$product_id . "'");

        // Build poa -> option_value map
        $tbl = DB_PREFIX . "bg_poa_map";
        $map2 = $this->db->query("SELECT poa_id, option_value_id FROM `" . $tbl . "` WHERE bg_id = '" . $this->db->escape($bg_id) . "'")->rows;
        $poa_to_option_value = array();
        foreach ($map2 as $mrow) $poa_to_option_value[(string)$mrow['poa_id']] = (int)$mrow['option_value_id'];

        // Update product_option_value for each mapped POA
        $allow_pov_overwrite = $this->allowPovOverwrite();
        foreach ($map_q->rows as $r) {
            $poa_id = (string)$r['poa_id'];
            if (!isset($poa_to_option_value[$poa_id])) continue;
            $optval_id = (int)$poa_to_option_value[$poa_id];
            $modifier = isset($r['price_modifier']) ? (float)$r['price_modifier'] : null;
            $stock = isset($r['product_stock']) ? $r['product_stock'] : null;

            if ($modifier !== null) {
                $price_val = abs((float)$modifier);
                $prefix = ((float)$modifier < 0) ? '-' : '+';
                $base_update = "UPDATE `" . DB_PREFIX . "product_option_value` pov
                    JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = " . (int)$optval_id . "
                    SET pov.price = '" . (float)$price_val . "', pov.price_prefix = '" . $this->db->escape($prefix) . "'";
                if (is_numeric($stock)) $base_update .= ", pov.quantity = " . (int)$stock;
                $base_update .= " WHERE pov.product_id = '" . (int)$product_id . "' AND pov.option_value_id = " . (int)$optval_id;

                if ($allow_pov_overwrite) {
                    $this->db->query($base_update);
                } else {
                    // only update rows where quantity == 0 to preserve existing non-zero values
                    $this->db->query($base_update . " AND pov.quantity = 0");
                }
            }
        }

        // Update Ship From option_value for this warehouse (exact name match)
        $ovq = $this->db->query(
            "SELECT ov.option_value_id FROM `" . DB_PREFIX . "option_value` ov
             JOIN `" . DB_PREFIX . "option_value_description` ovd ON ov.option_value_id = ovd.option_value_id
             WHERE ovd.name = '" . $this->db->escape($warehouse_key) . "' LIMIT 1"
        );
        if ($ovq->num_rows) {
            $ship_optval_id = (int)$ovq->row['option_value_id'];
            $total_wh_stock = 0;
            foreach ($map_q->rows as $r) { if (is_numeric($r['product_stock'])) $total_wh_stock += (int)$r['product_stock']; }
            if ($this->allowPovOverwrite()) {
                $this->db->query(
                    "UPDATE `" . DB_PREFIX . "product_option_value` SET price = 0.00, price_prefix = '+', quantity = " . (int)$total_wh_stock .
                    " WHERE product_id = '" . (int)$product_id . "' AND option_value_id = " . $ship_optval_id
                );
            } else {
                // preserve existing non-zero quantities
                $this->db->query(
                    "UPDATE `" . DB_PREFIX . "product_option_value` SET price = 0.00, price_prefix = '+'".
                    " WHERE product_id = '" . (int)$product_id . "' AND option_value_id = " . $ship_optval_id . " AND quantity = 0"
                );
                // If row doesn't exist, insert handled elsewhere
            }
        }

        return true;
    }

    protected function getOptionIdByName($name) {
        $name_esc = $this->db->escape($name);
        $sql = "SELECT option_id FROM `" . DB_PREFIX . "option_description` WHERE `name` = '" . $name_esc . "' LIMIT 1";
        $query = $this->db->query($sql);
        if ($query->num_rows) return (int)$query->row['option_id'];
        return 0;
    }

    /**
     * Normalize option group names coming from Banggood so imports consistently reuse
     * the same OpenCart option names (e.g. "Color" not "Color 1", "Size" not "Size 1").
     *
     * Keep this narrowly scoped to the commonly duplicated groups.
     */
    protected function normalizeImportedOptionName($name) {
        if ($name === null) return '';
        $name = html_entity_decode((string)$name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('/\s+/u', ' ', trim($name));

        // Only normalize the known duplicated groups ("Color"/"Size") to avoid changing unrelated option names.
        if (!preg_match('/^(color|colour|size)\b/i', $name)) return $name;

        // Remove trailing punctuation/spacers (e.g. "Color.", "Color:", "Size -", "Size_")
        $name = preg_replace('/[\s\.\:\-–—_]+$/u', '', $name);

        // Normalize compact forms like "Color1" / "Size2" -> "Color 1" / "Size 2"
        if (preg_match('/^(color|colour|size)(\d+)$/i', $name, $m)) {
            $name = $m[1] . ' ' . $m[2];
        }

        // Canonicalize: "Color", "Color 1", "colour", etc -> "Color"
        if (preg_match('/^(color|colour)(?:\s+\d+)?$/i', $name)) return 'Color';
        // Canonicalize: "Size", "Size 1", etc -> "Size"
        if (preg_match('/^size(?:\s+\d+)?$/i', $name)) return 'Size';

        return $name;
    }

    protected function addOption($name, $type = 'radio') {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "option` SET `type` = '" . $this->db->escape($type) . "', `sort_order` = 0");
        $option_id = $this->db->getLastId();
        $this->load->model('localisation/language');
        $languages = $this->model_localisation_language->getLanguages();
        foreach ($languages as $language) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "option_description` SET `option_id` = '" . (int)$option_id . "', `language_id` = '" . (int)$language['language_id'] . "', `name` = '" . $this->db->escape($name) . "'");
        }
        return (int)$option_id;
    }

    protected function getOptionValueIdByName($option_id, $value_name) {
        $value_name_esc = $this->db->escape($value_name);
        $sql = "SELECT ov.option_value_id FROM `" . DB_PREFIX . "option_value` ov LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd ON (ov.option_value_id = ovd.option_value_id) WHERE ov.option_id = '" . (int)$option_id . "' AND ovd.name = '" . $value_name_esc . "' LIMIT 1";
        $query = $this->db->query($sql);
        if ($query->num_rows) return (int)$query->row['option_value_id'];
        return 0;
    }

    protected function addOptionValue($option_id, $value_name, $image_url = '', $sort_order = 0, $bg_id = '') {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "option_value` SET `option_id` = '" . (int)$option_id . "', `image` = '', `sort_order` = '" . (int)$sort_order . "'");
        $option_value_id = $this->db->getLastId();

        $image_path = '';
        if (!empty($image_url)) {
            $image_path = $this->downloadOptionImage($image_url, $option_id, $option_value_id, $bg_id);
            $image_path = $this->toRelativeImagePath($image_path);
        }

        if ($image_path) {
            $this->db->query("UPDATE `" . DB_PREFIX . "option_value` SET `image` = '" . $this->db->escape($image_path) . "' WHERE `option_value_id` = '" . (int)$option_value_id . "'");
            // Warm OpenCart image cache so front-end uses /image/cache/ immediately
            try { $this->generateImageCacheForImages(array($image_path)); } catch (Exception $e) {}
        }

        $this->load->model('localisation/language');
        $languages = $this->model_localisation_language->getLanguages();
        foreach ($languages as $language) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "option_value_description` SET `option_value_id` = '" . (int)$option_value_id . "', `option_id` = '" . (int)$option_id . "', `language_id` = '" . (int)$language['language_id'] . "', `name` = '" . $this->db->escape($value_name) . "'");
        }
        return (int)$option_value_id;
    }

    protected function ensureOptionValueImage($option_value_id, $image_url, $bg_id = '', $option_name = '', $value_name = '') {
        if (empty($image_url)) return false;
        $q = $this->db->query("SELECT `image` FROM `" . DB_PREFIX . "option_value` WHERE `option_value_id` = '" . (int)$option_value_id . "' LIMIT 1");
        $current = ($q->num_rows ? $q->row['image'] : '');
        $overwrite = (bool)$this->config->get('module_banggood_import_overwrite_option_images');

        if (!empty($current) && !$overwrite) return false;

        $image_path = $this->downloadOptionImage($image_url, $option_name . '-' . $bg_id, $option_value_id, $bg_id);
        $image_path = $this->toRelativeImagePath($image_path);
        if ($image_path) {
            $this->db->query("UPDATE `" . DB_PREFIX . "option_value` SET `image` = '" . $this->db->escape($image_path) . "' WHERE `option_value_id` = '" . (int)$option_value_id . "'");
            // Warm OpenCart image cache so front-end uses /image/cache/ immediately
            try { $this->generateImageCacheForImages(array($image_path)); } catch (Exception $e) {}
            return true;
        }
        return false;
    }

    /* -------------------------
       Attributes extraction (table -> product_attribute)
       Also: persist the original table HTML as a product custom tab (bg_product_customtab / bg_product_customtab_description)
       ------------------------- */
    protected function extractTablesFromHtml($html) {
        $result = array('clean' => (string)$html, 'tables' => array());
        if (empty($html) || !is_string($html)) return $result;
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="bg_wrap">' . $html . '</div>');
        libxml_clear_errors();
        if (!$loaded) return $result;
        $xpath = new DOMXPath($dom);
        $table_nodes = $xpath->query("//div[@id='bg_wrap']//table");
        $tables = array(); $nodes_to_remove = array();
        foreach ($table_nodes as $tn) $nodes_to_remove[] = $tn;
        foreach ($nodes_to_remove as $tn) {
            $table_html = $dom->saveHTML($tn);
            if ($table_html) $tables[] = $table_html;
            if ($tn->parentNode) $tn->parentNode->removeChild($tn);
        }
        $wrapper = $dom->getElementById('bg_wrap');
        $clean_parts = array();
        if ($wrapper) { foreach ($wrapper->childNodes as $child) $clean_parts[] = $dom->saveHTML($child); }
        $clean_html = trim(implode('', $clean_parts));
        $clean_html = preg_replace('#\s{2,}#', ' ', $clean_html);
        $clean_html = trim($clean_html);
        return array('clean' => $clean_html, 'tables' => $tables);
    }

    protected function parseDescriptionTableToAttributes($html, $bg_id = '', $product_id = 0, $group_name = 'Size Chart') {
        if (empty($html) || !is_string($html) || empty($product_id)) return;
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>');
        libxml_clear_errors();
        if (!$loaded) return;
        $xpath = new DOMXPath($dom);
        $table_nodes = $xpath->query("//table[contains(@class,'table_inch')]");
        if ($table_nodes->length === 0) $table_nodes = $xpath->query("//table");
        if ($table_nodes->length === 0) return;
        $table = $table_nodes->item(0);
        $rows = array();
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = array();
            foreach ($tr->childNodes as $cn) {
                if ($cn->nodeType !== XML_ELEMENT_NODE) continue;
                $tag = strtolower($cn->nodeName);
                if ($tag === 'td' || $tag === 'th') $cells[] = trim(preg_replace('/\s+/', ' ', $cn->textContent));
            }
            if (!empty($cells)) $rows[] = $cells;
        }
        if (count($rows) < 2) {
            // persist as custom tab only if configured to do so
            if ($this->config->get('module_banggood_import_persist_customtab')) {
                try {
                    $this->addOrUpdateProductCustomTab($product_id, $group_name, $html, 0, 1);
                } catch (Exception $e) {
                    error_log('Banggood: failed to add/update custom tab: ' . $e->getMessage());
                }
            }
            return;
        }
        $header = $rows[0];
        $attributes = array();
        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r]; if (count($row) === 0) continue;
            $name = isset($row[0]) ? trim($row[0]) : ''; if ($name === '') continue;
            $parts = array();
            for ($c = 1; $c < count($header); $c++) {
                $colName = isset($header[$c]) ? trim($header[$c]) : '';
                $colVal = isset($row[$c]) ? trim($row[$c]) : '';
                if ($colName === '' || $colVal === '') continue;
                $parts[] = $colName . ': ' . $colVal;
            }
            if (empty($parts)) continue;
            $text = implode('; ', $parts);
            $attributes[] = array('name' => $name, 'text' => $text);
        }
        if (empty($attributes)) {
            if ($this->config->get('module_banggood_import_persist_customtab')) {
                try {
                    $this->addOrUpdateProductCustomTab($product_id, $group_name, $html, 0, 1);
                } catch (Exception $e) {
                    error_log('Banggood: failed to add/update custom tab: ' . $e->getMessage());
                }
            }
            return;
        }
        $group_id = $this->getAttributeGroupIdByName($group_name);
        if (!$group_id) $group_id = $this->addAttributeGroup($group_name);
        if (!$group_id) return;
        $this->load->model('localisation/language');
        $languages = $this->model_localisation_language->getLanguages();
        foreach ($attributes as $attr) {
            $attr_name = $attr['name']; $attr_text = $attr['text'];
            $attribute_id = $this->getAttributeIdByName($attr_name);
            if (!$attribute_id) $attribute_id = $this->addAttribute($group_id, $attr_name);
            if (!$attribute_id) continue;
            foreach ($languages as $language) {
                $language_id = (int)$language['language_id'];
                $q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_attribute` WHERE product_id = '" . (int)$product_id . "' AND attribute_id = '" . (int)$attribute_id . "' AND language_id = '" . (int)$language_id . "' LIMIT 1");
                $text_escaped = $this->db->escape($attr_text);
                if ($q->num_rows) $this->db->query("UPDATE `" . DB_PREFIX . "product_attribute` SET `text` = '" . $text_escaped . "' WHERE product_id = '" . (int)$product_id . "' AND attribute_id = '" . (int)$attribute_id . "' AND language_id = '" . (int)$language_id . "'");
                else $this->db->query("INSERT INTO `" . DB_PREFIX . "product_attribute` SET product_id = '" . (int)$product_id . "', attribute_id = '" . (int)$attribute_id . "', language_id = '" . (int)$language_id . "', `text` = '" . $text_escaped . "'");
            }
        }

        // Also persist the entire table HTML as a product custom tab if configured
        if ($this->config->get('module_banggood_import_persist_customtab')) {
            try {
                $this->addOrUpdateProductCustomTab($product_id, $group_name, $html, 0, 1);
            } catch (Exception $e) {
                error_log('Banggood: failed to add/update custom tab: ' . $e->getMessage());
            }
        }
    }

    /* -------------------------
       Product custom tab helpers
       - The module now uses BG-prefixed tables to avoid conflicts with core/admin that manipulate oc_product_customtab
       - addOrUpdateProductCustomTab: creates or updates a product custom tab per product and title
       - Preserves existing tabs unless overwrite flag enabled
       ------------------------- */
    protected function getProductCustomTabIdByTitle($product_id, $title) {
        $title_esc = $this->db->escape($title);
        $tbl_desc = DB_PREFIX . "bg_product_customtab_description";
        $query = $this->db->query("SELECT product_customtab_id FROM `" . $tbl_desc . "` WHERE product_id = '" . (int)$product_id . "' AND title = '" . $title_esc . "' LIMIT 1");
        if ($query->num_rows) return (int)$query->row['product_customtab_id'];
        return 0;
    }
    protected function addOrUpdateProductCustomTab($product_id, $title, $html, $sort_order = 0, $status = 1) {
        if (empty($product_id) || $title === '') return 0;

        $allow_overwrite = (bool)$this->config->get('module_banggood_import_overwrite_custom_tabs');

        $tbl_master = DB_PREFIX . "bg_product_customtab";
        $tbl_desc = DB_PREFIX . "bg_product_customtab_description";

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . $tbl_master . "` (
            `product_customtab_id` int(11) NOT NULL AUTO_INCREMENT,
            `product_id` int(11) NOT NULL,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `status` tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`product_customtab_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . $tbl_desc . "` (
            `product_customtab_id` int(11) NOT NULL,
            `language_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text NOT NULL
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");

        $existing_id = $this->getProductCustomTabIdByTitle($product_id, $title);
        $this->load->model('localisation/language');
        $languages = $this->model_localisation_language->getLanguages();
        if ($existing_id) {
            if (!$allow_overwrite) {
                return (int)$existing_id;
            }
            // update master row
            $this->db->query("UPDATE `" . $tbl_master . "` SET sort_order = '" . (int)$sort_order . "', status = '" . (int)$status . "' WHERE product_customtab_id = '" . (int)$existing_id . "'");
            // update descriptions per language
            foreach ($languages as $language) {
                $this->db->query("UPDATE `" . $tbl_desc . "` SET description = '" . $this->db->escape($html) . "' WHERE product_customtab_id = '" . (int)$existing_id . "' AND language_id = '" . (int)$language['language_id'] . "' AND product_id = '" . (int)$product_id . "'");
                // If not present for some reason, insert
                $q = $this->db->query("SELECT * FROM `" . $tbl_desc . "` WHERE product_customtab_id = '" . (int)$existing_id . "' AND language_id = '" . (int)$language['language_id'] . "' AND product_id = '" . (int)$product_id . "' LIMIT 1");
                if (!$q->num_rows) {
                    $this->db->query("INSERT INTO `" . $tbl_desc . "` SET product_customtab_id = '" . (int)$existing_id . "', language_id = '" . (int)$language['language_id'] . "', product_id = '" . (int)$product_id . "', title = '" . $this->db->escape($title) . "', description = '" . $this->db->escape($html) . "'");
                }
            }
            return (int)$existing_id;
        } else {
            // insert master
            $this->db->query("INSERT INTO `" . $tbl_master . "` SET product_id = '" . (int)$product_id . "', sort_order = '" . (int)$sort_order . "', status = '" . (int)$status . "'");
            $new_id = $this->db->getLastId();
            foreach ($languages as $language) {
                $this->db->query("INSERT INTO `" . $tbl_desc . "` SET product_customtab_id = '" . (int)$new_id . "', language_id = '" . (int)$language['language_id'] . "', product_id = '" . (int)$product_id . "', title = '" . $this->db->escape($title) . "', description = '" . $this->db->escape($html) . "'");
            }
            return (int)$new_id;
        }
    }

    protected function getAttributeGroupIdByName($name) {
        $name_esc = $this->db->escape($name);
        $sql = "SELECT attribute_group_id FROM `" . DB_PREFIX . "attribute_group_description` WHERE `name` = '" . $name_esc . "' LIMIT 1";
        $query = $this->db->query($sql);
        if ($query->num_rows) return (int)$query->row['attribute_group_id'];
        return 0;
    }

    protected function addAttributeGroup($name) {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group` SET sort_order = 0");
        $attribute_group_id = $this->db->getLastId();
        $this->load->model('localisation/language');
        $languages = $this->model_localisation_language->getLanguages();
        foreach ($languages as $language) $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group_description` SET attribute_group_id = '" . (int)$attribute_group_id . "', language_id = '" . (int)$language['language_id'] . "', name = '" . $this->db->escape($name) . "'");
        return (int)$attribute_group_id;
    }

    protected function getAttributeIdByName($name) {
        $name_esc = $this->db->escape($name);
        $sql = "SELECT attribute_id FROM `" . DB_PREFIX . "attribute_description` WHERE `name` = '" . $name_esc . "' LIMIT 1";
        $query = $this->db->query($sql);
        if ($query->num_rows) return (int)$query->row['attribute_id'];
        return 0;
    }

    protected function addAttribute($attribute_group_id, $name) {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute` SET attribute_group_id = '" . (int)$attribute_group_id . "', sort_order = 0");
        $attribute_id = $this->db->getLastId();
        $this->load->model('localisation/language');
        $languages = $this->model_localisation_language->getLanguages();
        foreach ($languages as $language) $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_description` SET attribute_id = '" . (int)$attribute_id . "', language_id = '" . (int)$language['language_id'] . "', name = '" . $this->db->escape($name) . "'");
        return (int)$attribute_id;
    }

    /* -------------------------
       Image cache generation helper (creates OpenCart cache via model_tool_image->resize)
       - Safe: checks file existence and wraps calls in try/catch
       ------------------------- */
    protected function generateImageCacheForImages(array $images) {
        if (empty($images)) return;
        try {
            $this->load->model('tool/image');
        } catch (Exception $e) {
            error_log('Banggood: could not load model_tool_image: ' . $e->getMessage());
            return;
        }

        // OpenCart 3.x uses *_width and *_height config keys.
        // We also add common Banggood POA sizes so option-image cache is warmed immediately.
        $sizes = array();
        $seen = array();
        $addSize = function($w, $h) use (&$sizes, &$seen) {
            $w = (int)$w; $h = (int)$h;
            if ($w <= 0 || $h <= 0) return;
            $k = $w . 'x' . $h;
            if (isset($seen[$k])) return;
            $seen[$k] = true;
            $sizes[] = array('w' => $w, 'h' => $h);
        };

        $pairs = array(
            array('config_image_thumb_width', 'config_image_thumb_height'),
            array('config_image_popup_width', 'config_image_popup_height'),
            array('config_image_product_width', 'config_image_product_height'),
            array('config_image_additional_width', 'config_image_additional_height'),
            array('config_image_related_width', 'config_image_related_height'),
            array('config_image_compare_width', 'config_image_compare_height'),
            array('config_image_wishlist_width', 'config_image_wishlist_height'),
            array('config_image_cart_width', 'config_image_cart_height'),
            array('config_image_category_width', 'config_image_category_height'),
        );
        foreach ($pairs as $p) {
            $addSize($this->config->get($p[0]), $this->config->get($p[1]));
        }

        // Legacy fallbacks (some installs may store square sizes without *_width/_height)
        $legacyKeys = array('config_image_thumb', 'config_image_popup', 'config_image_product', 'config_image_additional');
        foreach ($legacyKeys as $k) {
            $v = (int)$this->config->get($k);
            if ($v > 0) $addSize($v, $v);
        }

        // Banggood option images commonly used sizes
        $addSize(50, 50);
        $addSize(120, 120);
        $addSize(360, 360);
        $addSize(600, 600);

        foreach ($images as $img) {
            $img_rel = $this->toRelativeImagePath($img);
            if (!$img_rel) continue;
            $full = DIR_IMAGE . $img_rel;
            if (!is_file($full)) continue;
            foreach ($sizes as $s) {
                if ((int)$s['w'] <= 0 || (int)$s['h'] <= 0) continue;
                try {
                    $this->model_tool_image->resize($img_rel, (int)$s['w'], (int)$s['h']);
                } catch (Exception $e) {
                    error_log('Banggood: resize error for ' . $img_rel . ' ' . $s['w'] . 'x' . $s['h'] . ' - ' . $e->getMessage());
                }
            }
        }
    }

    /* -------------------------
       Helper: write jpeg from arbitrary image bytes
       - tries GD first, then Imagick, then system 'convert'
       Returns true if JPEG written, false otherwise
       ------------------------- */
    protected function writeJpegFromData($data, $local_path) {
        $im = @imagecreatefromstring($data);
        if ($im !== false) {
            $dir = dirname($local_path);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $ok = @imagejpeg($im, $local_path, 90);
            @imagedestroy($im);
            if ($ok) {
                @chmod($local_path, 0644);
                if (@getimagesize($local_path)) return true;
            }
        }

        if (class_exists('Imagick')) {
            try {
                $imw = new Imagick();
                $imw->readImageBlob($data);
                if ($imw->getNumberImages() > 1) {
                    $coalesced = $imw->coalesceImages();
                    foreach ($coalesced as $frame) { $first = $frame; break; }
                    $imw->clear(); $imw->destroy(); $imw = $first;
                }
                $imw->setImageFormat('jpeg');
                $imw->setImageCompressionQuality(90);
                $dir = dirname($local_path);
                if (!is_dir($dir)) @mkdir($dir, 0777, true);
                $imw->writeImage($local_path);
                $imw->clear(); $imw->destroy();
                @chmod($local_path, 0644);
                if (@getimagesize($local_path)) return true;
            } catch (Exception $e) {
                error_log('Banggood: Imagick conversion failed: ' . $e->getMessage());
            }
        }

        $exec_available = false;
        if (function_exists('exec') || function_exists('shell_exec')) $exec_available = true;

        if ($exec_available) {
            $convert_path = null;
            try {
                if (function_exists('shell_exec')) {
                    $which = @trim(shell_exec('command -v convert 2>/dev/null'));
                    if ($which) $convert_path = $which;
                }
                if (!$convert_path && function_exists('exec')) {
                    @exec('command -v convert 2>/dev/null', $out, $rc);
                    if (!empty($out[0])) $convert_path = trim($out[0]);
                }
            } catch (Exception $e) {
                $convert_path = null;
            }

            if ($convert_path) {
                $tmp_in = tempnam(sys_get_temp_dir(), 'bgimg_in_');
                if ($tmp_in !== false) {
                    $written = @file_put_contents($tmp_in, $data);
                    if ($written !== false) {
                        $dir = dirname($local_path);
                        if (!is_dir($dir)) @mkdir($dir, 0777, true);
                        $cmd = escapeshellcmd($convert_path) . ' ' . escapeshellarg($tmp_in) . ' -strip -quality 90 ' . escapeshellarg($local_path) . ' 2>&1';
                        @exec($cmd, $convert_out, $convert_rc);
                        @unlink($tmp_in);
                        if ($convert_rc === 0 && is_file($local_path) && @getimagesize($local_path)) {
                            @chmod($local_path, 0644);
                            return true;
                        } else {
                            $msg = is_array($convert_out) ? implode("\n", $convert_out) : (string)$convert_out;
                            error_log('Banggood: ImageMagick convert failed (rc=' . intval($convert_rc) . '): ' . $msg);
                            if (is_file($local_path) && @filesize($local_path) === 0) @unlink($local_path);
                        }
                    } else {
                        @unlink($tmp_in);
                    }
                }
            } else {
                error_log('Banggood: convert binary not found.');
            }
        } else {
            error_log('Banggood: exec/shell_exec disabled; cannot call ImageMagick convert.');
        }

        error_log('Banggood: could not convert image blob to JPEG for path ' . $local_path . ' (GD, Imagick and convert failed).');
        return false;
    }

    /* -------------------------
       Helper: detect Out-of-Stock stock_status_id
       - tries common names in the configured admin language, falls back to config_stock_status_id
       ------------------------- */
    protected function detectOutOfStockStatusId() {
        $lang_id = (int)($this->config->get('config_language_id') ? $this->config->get('config_language_id') : 1);
        $candidates = array('Out Of Stock','Out of Stock','Out of stock','Sold Out','Sold out');

        foreach ($candidates as $name) {
            $q = $this->db->query("SELECT stock_status_id FROM `" . DB_PREFIX . "stock_status` WHERE name = '" . $this->db->escape($name) . "' AND language_id = " . $lang_id . " LIMIT 1");
            if ($q->num_rows) return (int)$q->row['stock_status_id'];
        }

        // fallback: return configured stock status id
        return (int)$this->config->get('config_stock_status_id');
    }

    /* ---------------------------------------------------------------------
       NEW: refreshBgCategoriesFromApi() - fetch category tree from Banggood API, flatten and upsert into bg_category
       - This method is additive and will not remove any existing code.
       - It is synchronous and may time out for very large trees; consider background processing if needed.
    --------------------------------------------------------------------- */
    public function refreshBgCategoriesFromApi() {
        $inserted = 0;
        $updated = 0;

        $config = $this->getBanggoodConfig();

        // try configured endpoint override first; allow full URL or path
        $endpoint_setting = $this->config->get('module_banggood_import_category_endpoint');
        $tasks = array();
        if ($endpoint_setting) {
            $tasks[] = $endpoint_setting;
        }
        // commons and additional variants
        $tasks = array_merge($tasks, array(
            'category/getList',
        ));

        $raw = null;
        $lastException = null;
        foreach ($tasks as $task) {
            try {
                $resp = $this->apiRequestRaw($config, $task, 'GET', array('lang' => $config['lang']));
                $candidate = $this->findCandidateInResponse($resp);
                if ($candidate !== null && is_array($candidate) && !empty($candidate)) {
                    $raw = $candidate;
                    break;
                }
                // If candidate not found, but response itself looks like categories, use it.
                if (is_array($resp) && $this->looksLikeCategoryArray($resp)) {
                    $raw = $resp;
                    break;
                }
                // If we got a string (raw HTML or text), try findCandidateInResponse will handle it above (string branch).
                if (is_string($resp)) {
                    $candidateFromString = $this->findCandidateInResponse($resp);
                    if ($candidateFromString !== null && is_array($candidateFromString) && !empty($candidateFromString)) {
                        $raw = $candidateFromString;
                        break;
                    }
                }
            } catch (Exception $e) {
                $lastException = $e;
                continue;
            }
        }

        if ($raw === null) {
            throw new Exception('Could not fetch/normalize categories from API. Last error: ' . ($lastException ? $lastException->getMessage() : 'no response'));
        }

        // root is raw (could be nested tree or flat list)
        $flat = array();
        $this->flattenCategoryNodes($raw, null, $flat);

        if (empty($flat)) {
            throw new Exception('API returned categories but normalization produced no rows.');
        }

        // Ensure target table exists (support both schemas)
        $targetTable = DB_PREFIX . 'bg_category';
        $this->ensureTargetTableExists($targetTable); // creates cat_id style if missing
        $this->ensureBgCategoryTableExists(); // ensure bgc table schema exists if needed

        // Determine which schema to upsert into by inspecting columns
        $cols = $this->getTableColumns($targetTable);
        $has_bg_cat_id = in_array('bg_cat_id', $cols);
        $has_cat_id = in_array('cat_id', $cols);

        // If bg_cat_id schema present, ensure product_count column exists
        if ($has_bg_cat_id) {
            if (!in_array('product_count', $cols)) {
                $this->db->query("ALTER TABLE `" . $this->db->escape($targetTable) . "` ADD COLUMN `product_count` INT DEFAULT 0");
                $cols[] = 'product_count';
            }
            // Upsert: insert initial rows (parent to be linked in second pass)
            foreach ($flat as $r) {
                $bg_cat_id = isset($r['bg_cat_id']) ? (string)$r['bg_cat_id'] : (isset($r['src_id']) ? (string)$r['src_id'] : '');
                if ($bg_cat_id === '') continue;
                $name = isset($r['name']) ? $r['name'] : '';
                $sort_order = isset($r['sort_order']) ? (int)$r['sort_order'] : 0;
                $product_count = isset($r['product_count']) ? (int)$r['product_count'] : 0;

                // check exists
                $q = $this->db->query("SELECT bgc_id, name, sort_order, product_count FROM `" . $this->db->escape($targetTable) . "` WHERE bg_cat_id = '" . $this->db->escape($bg_cat_id) . "' LIMIT 1");
                if ($q->num_rows) {
                    $need = false;
                    if ($q->row['name'] !== $name) $need = true;
                    if ((int)$q->row['sort_order'] !== $sort_order) $need = true;
                    if (isset($q->row['product_count']) && (int)$q->row['product_count'] !== $product_count) $need = true;
                    if ($need) {
                        $this->db->query("UPDATE `" . $this->db->escape($targetTable) . "` SET `name` = '" . $this->db->escape($name) . "', `sort_order` = " . (int)$sort_order . ", `product_count` = " . (int)$product_count . " WHERE bg_cat_id = '" . $this->db->escape($bg_cat_id) . "' LIMIT 1");
                        $updated++;
                    }
                } else {
                    $this->db->query("INSERT INTO `" . $this->db->escape($targetTable) . "` (`bg_cat_id`, `parent_id`, `name`, `sort_order`, `created_at`) VALUES ('" . $this->db->escape($bg_cat_id) . "', 0, '" . $this->db->escape($name) . "', " . (int)$sort_order . ", NOW())");
                    if (in_array('product_count', $cols)) {
                        $this->db->query("UPDATE `" . $this->db->escape($targetTable) . "` SET product_count = " . (int)$product_count . " WHERE bg_cat_id = '" . $this->db->escape($bg_cat_id) . "' LIMIT 1");
                    }
                    $inserted++;
                }
            }

            // Second pass: set parent_id by resolving parent bg_cat_id -> bgc_id
            foreach ($flat as $r) {
                $bg_cat_id = isset($r['bg_cat_id']) ? (string)$r['bg_cat_id'] : (isset($r['src_id']) ? (string)$r['src_id'] : '');
                $parent_bg = isset($r['parent_bg_cat_id']) ? (string)$r['parent_bg_cat_id'] : (isset($r['parent']) ? (string)$r['parent'] : null);
                if ($bg_cat_id === '') continue;
                $parent_id_val = 0;
                if ($parent_bg !== null && $parent_bg !== '' && $parent_bg !== '0') {
                    $pq = $this->db->query("SELECT bgc_id FROM `" . $this->db->escape($targetTable) . "` WHERE bg_cat_id = '" . $this->db->escape($parent_bg) . "' LIMIT 1");
                    if ($pq->num_rows) $parent_id_val = (int)$pq->row['bgc_id'];
                }
                // update parent_id if different
                $this->db->query("UPDATE `" . $this->db->escape($targetTable) . "` SET parent_id = " . (int)$parent_id_val . " WHERE bg_cat_id = '" . $this->db->escape($bg_cat_id) . "'");
            }

        } elseif ($has_cat_id) {
            // Legacy schema: cat_id, parent_cat_id, name
            // Ensure product_count exists
            if (!in_array('product_count', $cols)) {
                $this->db->query("ALTER TABLE `" . $this->db->escape($targetTable) . "` ADD COLUMN `product_count` INT DEFAULT 0");

                $cols[] = 'product_count';
            }
            foreach ($flat as $r) {
                $cat_id = isset($r['bg_cat_id']) ? (string)$r['bg_cat_id'] : (isset($r['src_id']) ? (string)$r['src_id'] : '');
                if ($cat_id === '') continue;
                $parent = isset($r['parent_bg_cat_id']) ? (string)$r['parent_bg_cat_id'] : (isset($r['parent']) ? (string)$r['parent'] : '0');
                $name = isset($r['name']) ? $r['name'] : '';
                $product_count = isset($r['product_count']) ? (int)$r['product_count'] : 0;

                $q = $this->db->query("SELECT cat_id, name, parent_cat_id, product_count FROM `" . $this->db->escape($targetTable) . "` WHERE cat_id = '" . $this->db->escape($cat_id) . "' LIMIT 1");
                if ($q->num_rows) {
                    $need = false;
                    if ($q->row['name'] !== $name) $need = true;
                    if ((string)$q->row['parent_cat_id'] !== (string)$parent) $need = true;
                    if (isset($q->row['product_count']) && (int)$q->row['product_count'] !== $product_count) $need = true;
                    if ($need) {
                        $this->db->query("UPDATE `" . $this->db->escape($targetTable) . "` SET `parent_cat_id` = '" . $this->db->escape($parent) . "', `name` = '" . $this->db->escape($name) . "', `product_count` = " . (int)$product_count . " WHERE `cat_id` = '" . $this->db->escape($cat_id) . "' LIMIT 1");
                        $updated++;
                    }
                } else {
                    $this->db->query("INSERT INTO `" . $this->db->escape($targetTable) . "` (`cat_id`, `parent_cat_id`, `name`, `product_count`) VALUES ('" . $this->db->escape($cat_id) . "', '" . $this->db->escape($parent) . "', '" . $this->db->escape($name) . "', " . (int)$product_count . ")");
                    $inserted++;
                }
            }
        } else {
            throw new Exception('bg_category table exists but schema is unrecognized (neither bg_cat_id nor cat_id found).');
        }

        // Return HTML rendering of the current target table
        $html = $this->getBgCategoriesHtml();

        return array('inserted' => $inserted, 'updated' => $updated, 'html' => $html);
    }

    /**
     * Existing local refresh (keeps compatibility). If you already have this implemented,
     * keep it or merge with the previous version. It reads from local import table(s)
     * and upserts into DB_PREFIX . 'bg_category'.
     *
     * NOTE: This method is left as-is (user provided) and will be used when API isn't configured.
     */
    public function refreshBgCategories() {
        $inserted = 0;
        $updated = 0;

        // Determine source table candidates (import first)
        $sourceCandidates = [
            DB_PREFIX . 'bg_category_import',
            DB_PREFIX . 'bg_category'
        ];

        $sourceRows = array();
        $sourceMeta = array('id_column' => null, 'parent_column' => null, 'name_column' => null, 'table' => null);

        foreach ($sourceCandidates as $tbl) {
            if (!$this->tableExists($tbl)) {
                continue;
            }
            $cols = $this->getTableColumns($tbl);
            // try to find id/parent/name columns among common names
            $idCol = $this->findColumn($cols, ['cat_id', 'category_id', 'id']);
            $parentCol = $this->findColumn($cols, ['parent_cat_id', 'parent_id', 'parent']);
            $nameCol = $this->findColumn($cols, ['name', 'category_name', 'cat_name', 'title']);

            if ($idCol && $parentCol && $nameCol) {
                // fetch all rows
                $sql = "SELECT `" . $this->db->escape($idCol) . "` AS src_id, `" . $this->db->escape($parentCol) . "` AS src_parent, `" . $this->db->escape($nameCol) . "` AS src_name
                        FROM `" . $this->db->escape($tbl) . "`
                        ORDER BY `" . $this->db->escape($parentCol) . "`, `" . $this->db->escape($nameCol) . "`";
                $qr = $this->db->query($sql);
                if ($qr && $qr->rows) {
                    $sourceRows = $qr->rows;
                    $sourceMeta = [
                        'id_column' => $idCol,
                        'parent_column' => $parentCol,
                        'name_column' => $nameCol,
                        'table' => $tbl
                    ];
                    break;
                }
            }
        }

        // If no source rows found yet, fallback to core category tables
        if (empty($sourceRows)) {
            $tblCat = DB_PREFIX . 'category';
            $tblDesc = DB_PREFIX . 'category_description';
            if ($this->tableExists($tblCat) && $this->tableExists($tblDesc)) {
                $qr = $this->db->query(
                    "SELECT c.category_id AS src_id, c.parent_id AS src_parent, cd.name AS src_name
                     FROM `" . $this->db->escape($tblCat) . "` c
                     LEFT JOIN `" . $this->db->escape($tblDesc) . "` cd ON (c.category_id = cd.category_id)
                     WHERE cd.language_id = 1
                     ORDER BY c.parent_id, cd.name"
                );
                if ($qr && $qr->rows) {
                    $sourceRows = $qr->rows;
                    $sourceMeta = [
                        'id_column' => 'category_id',
                        'parent_column' => 'parent_id',
                        'name_column' => 'name',
                        'table' => $tblCat
                    ];
                }
            }
        }

        if (empty($sourceRows)) {
            // Nothing to import
            $html = $this->getBgCategoriesHtml(); // show current tree (may be empty)
            return array('inserted' => 0, 'updated' => 0, 'html' => $html);
        }

        // Ensure target table exists: DB_PREFIX . 'bg_category'
        $targetTable = DB_PREFIX . 'bg_category';
        $this->ensureTargetTableExists($targetTable);

        // Upsert rows: for each source row, insert or update target
        foreach ($sourceRows as $r) {
            $id = (string)$r['src_id'];
            $parent = isset($r['src_parent']) ? (string)$r['src_parent'] : '0';
            $name = isset($r['src_name']) ? $r['src_name'] : '';

            if ($id === '') continue;

            // check if exists
            $check = $this->db->query("SELECT 1 FROM `" . $this->db->escape($targetTable) . "` WHERE `cat_id` = '" . $this->db->escape($id) . "' LIMIT 1");
            if ($check->num_rows) {
                // update (only update name/parent)
                $this->db->query("UPDATE `" . $this->db->escape($targetTable) . "` SET `parent_cat_id` = '" . $this->db->escape($parent) . "', `name` = '" . $this->db->escape($name) . "' WHERE `cat_id` = '" . $this->db->escape($id) . "' LIMIT 1");
                $updated++;
            } else {
                // insert
                $this->db->query("INSERT INTO `" . $this->db->escape($targetTable) . "` (`cat_id`, `parent_cat_id`, `name`) VALUES ('" . $this->db->escape($id) . "', '" . $this->db->escape($parent) . "', '" . $this->db->escape($name) . "')");
                $inserted++;
            }
        }

        // Build HTML tree from target table for return (collapsed by default)
        $html = $this->getBgCategoriesHtml();

        return array('inserted' => $inserted, 'updated' => $updated, 'html' => $html);
    }

    /**
     * Build nested HTML tree from DB_PREFIX . 'bg_category' target table.
     * Child ULs are rendered hidden (style="display:none") so the tree loads collapsed.
     * This function supports both bg_cat_id (bgc_id parent_id) schema and cat_id parent_cat_id schema.
     * It will show the external BG category id and product_count if available.
     */
    public function getBgCategoriesHtml() {
        $targetTable = DB_PREFIX . 'bg_category';
        if (!$this->tableExists($targetTable)) {
            return '<div class="text-muted">No bg_category table found.</div>';
        }

        $cols = $this->getTableColumns($targetTable);
        $has_bg_cat_id = in_array('bg_cat_id', $cols);
        $has_cat_id = in_array('cat_id', $cols);
        $has_product_count = in_array('product_count', $cols);

        // bg_cat_id schema (preferred if present)
        if ($has_bg_cat_id) {
            // Our schema stores the *real Banggood category id* in bg_cat_id (string),
            // and the *real parent Banggood category id* in parent_id (string).
            // Do NOT assume internal numeric ids like bgc_id.
            $has_parent_id = in_array('parent_id', $cols);
            $q = $this->db->query(
                "SELECT `bg_cat_id`, " . ($has_parent_id ? "`parent_id`" : "'' AS parent_id") . ", `name`" .
                ($has_product_count ? ", `product_count`" : ", 0 AS product_count") .
                " FROM `" . $this->db->escape($targetTable) . "` ORDER BY `name`"
            );
            if (!$q || !$q->rows) return '<div class="text-muted">No categories found.</div>';

            $rows = $q->rows;
            $byParent = array();
            $allIds = array();
            foreach ($rows as $r) {
                $id = isset($r['bg_cat_id']) ? (string)$r['bg_cat_id'] : '';
                if ($id === '') continue;
                $parent = isset($r['parent_id']) ? (string)$r['parent_id'] : '0';
                $allIds[$id] = true;
                $byParent[$parent][] = $r;
            }

            // Render tree with correct id badge (#cat_id) and predictable classes used by admin JS.
            $build = function($parentId, $isChild = false) use (&$build, $byParent) {
                if (!isset($byParent[$parentId]) || !count($byParent[$parentId])) return '';
                $style = $isChild ? ' style="display:none; list-style:none; padding-left:14px; margin:0;"' : ' style="list-style:none; padding-left:14px; margin:0;"';
                $html = '<ul class="bg-tree"' . $style . '>';
                foreach ($byParent[$parentId] as $node) {
                    $id = isset($node['bg_cat_id']) ? (string)$node['bg_cat_id'] : '';
                    if ($id === '') continue;
                    $hasChildren = isset($byParent[$id]) && count($byParent[$id]);
                    $label = htmlspecialchars((string)$node['name'], ENT_QUOTES, 'UTF-8');
                    $prodCount = isset($node['product_count']) ? (int)$node['product_count'] : 0;

                    $html .= '<li class="bg-node' . ($hasChildren ? ' has-children' : '') . '" data-bgc-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">';
                    if ($hasChildren) {
                        $html .= '<button type="button" class="bg-toggle" aria-expanded="false" style="appearance:none;-webkit-appearance:none;border:0;background:transparent;padding:0;margin-right:6px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;font-size:14px;box-shadow:none;border-radius:2px;background-image:none;">&#9656;</button> ';
                    } else {
                        $html .= '<span class="bg-toggle" aria-hidden="true" style="visibility:hidden;display:inline-block;width:18px"></span> ';
                    }
                    $html .= '<span class="bg-label" style="cursor:pointer;">';
                    $html .= '<span class="bg-name">' . $label . '</span>';
                    $html .= '<span class="bg-id bg-id-box" data-bg-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">#' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '</span>';
                    if ($prodCount) $html .= ' <small style="color:#777">(' . (int)$prodCount . ')</small>';
                    $html .= '</span>';

                    $sub = $build($id, true);
                    if ($sub) $html .= $sub;
                    $html .= '</li>';
                }
                $html .= '</ul>';
                return $html;
            };

            // Determine roots
            if (isset($byParent['0'])) return $build('0', false);
            if (isset($byParent[''])) return $build('', false);

            // Fallback roots: parent not present as an id
            $roots = array();
            foreach ($rows as $r) {
                $id = isset($r['bg_cat_id']) ? (string)$r['bg_cat_id'] : '';
                if ($id === '') continue;
                $parent = isset($r['parent_id']) ? (string)$r['parent_id'] : '';
                if ($parent === '' || !isset($allIds[$parent])) $roots[] = $id;
            }
            $roots = array_values(array_unique($roots));
            if (empty($roots)) return $build('0', false);

            $out = '<ul class="bg-tree" style="list-style:none; padding-left:14px; margin:0;">';
            foreach ($roots as $rid) {
                $hasChildren = isset($byParent[$rid]) && count($byParent[$rid]);
                // Find name for root
                $name = $rid;
                foreach ($rows as $r) {
                    if ((string)$r['bg_cat_id'] === (string)$rid) { $name = (string)$r['name']; break; }
                }
                $label = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');
                $out .= '<li class="bg-node' . ($hasChildren ? ' has-children' : '') . '" data-bgc-id="' . htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') . '">';
                if ($hasChildren) {
                    $out .= '<button type="button" class="bg-toggle" aria-expanded="false" style="appearance:none;-webkit-appearance:none;border:0;background:transparent;padding:0;margin-right:6px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;font-size:14px;box-shadow:none;border-radius:2px;background-image:none;">&#9656;</button> ';
                } else {
                    $out .= '<span class="bg-toggle" aria-hidden="true" style="visibility:hidden;display:inline-block;width:18px"></span> ';
                }
                $out .= '<span class="bg-label" style="cursor:pointer;">';
                $out .= '<span class="bg-name">' . $label . '</span>';
                $out .= '<span class="bg-id bg-id-box" data-bg-id="' . htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') . '">#' . htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') . '</span>';
                $out .= '</span>';
                $out .= $build($rid, true);
                $out .= '</li>';
            }
            $out .= '</ul>';
            return $out;
        }

        // fallback: cat_id schema (legacy)
        if ($has_cat_id) {
            $q = $this->db->query("SELECT `cat_id`, `parent_cat_id`, `name`" . ($has_product_count ? ", `product_count`" : ", 0 AS product_count") . " FROM `" . $this->db->escape($targetTable) . "` ORDER BY `parent_cat_id`, `name`");
            if (!$q || !$q->rows) {
                return '<div class="text-muted">No categories found.</div>';
            }
            $rows = $q->rows;
            $byParent = array();
            $allIds = array();
            foreach ($rows as $r) {
                $id = (string)$r['cat_id'];
                $parent = (string)$r['parent_cat_id'];
                $allIds[$id] = true;
                $byParent[$parent][] = $r;
            }

            $build = function($parentId, $isChild = false) use (&$build, $byParent) {
                if (!isset($byParent[$parentId]) || !count($byParent[$parentId])) {
                    return '';
                }
                $style = $isChild ? ' style="display:none; list-style:none; padding-left:14px; margin:0;"' : ' style="list-style:none; padding-left:14px; margin:0;"';
                $html = '<ul' . $style . '>';
                foreach ($byParent[$parentId] as $node) {
                    $hasChildren = isset($byParent[$node['cat_id']]) && count($byParent[$node['cat_id']]);
                    $label = htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8');
                    $prodCount = isset($node['product_count']) ? (int)$node['product_count'] : 0;
                    $html .= '<li style="margin:4px 0;"' . ($hasChildren ? ' class="has-children"' : '') . '>';
                    if ($hasChildren) {
                        // show right-pointing arrow initially
                        $html .= '<span class="bg-toggle" style="cursor:pointer; display:inline-block; width:16px;">&#9656;</span> ';
                    } else {
                        $html .= '<span style="display:inline-block; width:16px;"></span> ';
                    }
                    $html .= '<span class="cat-label" data-cat-id="' . htmlspecialchars($node['cat_id'], ENT_QUOTES, 'UTF-8') . '" style="cursor:pointer;">' . $label . ($prodCount ? ' <small/style="color:#777">(' . $prodCount . ')</small>' : '') . '</span>';
                    // child subtree (hidden by default)
                    $sub = $build($node['cat_id'], true);
                    if ($sub) {
                        $html .= $sub;
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
                return $html;
            };

            // Determine appropriate root parent key(s).
            if (isset($byParent['0'])) {
                return $build('0', false);
            } elseif (isset($byParent[''])) {
                return $build('', false);
            } else {
                // find top-level nodes: those whose parent is not present as an id
                $topLevel = array();
                foreach ($rows as $r) {
                    $id = (string)$r['cat_id'];
                    $parent = (string)$r['parent_cat_id'];
                    if ($id === '') continue;
                    if ($parent === '' || !isset($allIds[$parent])) {
                        $topLevel[] = array('id' => $id, 'name' => $r['name'], 'product_count' => (int)$r['product_count']);
                    }
                }
                // render top-level nodes
                $rootHtml = '<ul style="list-style:none; padding-left:14px; margin:0;">';
                foreach ($topLevel as $node) {
                    $hasChildren = isset($byParent[$node['id']]) && count($byParent[$node['id']]);
                    $label = htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8');
                    $prodCount = isset($node['product_count']) ? (int)$node['product_count'] : 0;
                    $rootHtml .= '<li style="margin:4px 0;"' . ($hasChildren ? ' class="has-children"' : '') . '>';
                    if ($hasChildren) {
                        $rootHtml .= '<span class="bg-toggle" style="cursor:pointer; display:inline-block; width:16px;">&#9656;</span> ';
                    } else {
                        $rootHtml .= '<span style="display:inline-block; width:16px;"></span> ';
                    }
                    $rootHtml .= '<span class="cat-label" data-cat-id="' . htmlspecialchars($node['id'], ENT_QUOTES, 'UTF-8') . '" style="cursor:pointer;">' . $label . ($prodCount ? ' <small style="color:#777">(' . $prodCount . ')</small>' : '') . '</span>';
                    $rootHtml .= $build($node['id'], true);
                    $rootHtml .= '</li>';
                }
                $rootHtml .= '</ul>';
                return $rootHtml;
            }
        }

        return '<div class="text-muted">bg_category table schema not recognized.</div>';
    }

    /**
     * Utility: return column names for table
     */
    private function getTableColumns($table) {
        $cols = array();
        try {
            $res = $this->db->query("SHOW COLUMNS FROM `" . $this->db->escape($table) . "`");
            if ($res && $res->rows) {
                foreach ($res->rows as $r) {
                    $cols[] = $r['Field'];
                }
            }
        } catch (Exception $e) {
            // ignore
        }
        return $cols;
    }

    /**
     * Utility: check if table exists
     */
    private function tableExists($table) {
        try {
            $res = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($table) . "'");
            return ($res && $res->num_rows);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Utility: find first matching column from candidates list
     */
    private function findColumn($available, $candidates) {
        foreach ($candidates as $c) {
            if (in_array($c, $available)) return $c;
        }
        return null;
    }

    /**
     * Ensure target table exists with safe schema.
     * Will create table if missing.
     */
    private function ensureTargetTableExists($table) {
        if ($this->tableExists($table)) return;

        // Create a simple target table: cat_id (PK), parent_cat_id, name, sort_order, level
        $sql = "
        CREATE TABLE IF NOT EXISTS `" . $this->db->escape($table) . "` (
            `cat_id` VARCHAR(64) NOT NULL,
            `parent_cat_id` VARCHAR(64) DEFAULT '0',
            `name` VARCHAR(255) DEFAULT '',
            `sort_order` INT DEFAULT 0,
            `level` INT DEFAULT 0,
            PRIMARY KEY (`cat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ";
        $this->db->query($sql);
    }

    /**
     * Recursively flatten a category node set into a flat list with parent references.
     * Accepts various shapes (flat list where each row has parent_id, or nested arrays with children).
     */
    protected function flattenCategoryNodes($nodes, $parent_bg_cat_id = null, array &$out = array()) {
        if (!is_array($nodes)) return;
        // If nodes is associative representing a single category
        if ($this->isAssociative($nodes) && (isset($nodes['cat_id']) || isset($nodes['id']))) {
            $bg_cat_id = isset($nodes['cat_id']) ? (string)$nodes['cat_id'] : (string)$nodes['id'];
            $name = isset($nodes['cat_name']) ? $nodes['cat_name'] : (isset($nodes['name']) ? $nodes['name'] : '');
            $count = isset($nodes['product_count']) ? (int)$nodes['product_count'] : (isset($nodes['count']) ? (int)$nodes['count'] : 0);
            $out[] = array('bg_cat_id' => $bg_cat_id, 'parent_bg_cat_id' => $parent_bg_cat_id, 'name' => $name, 'sort_order' => isset($nodes['sort_order']) ? (int)$nodes['sort_order'] : 0, 'product_count' => $count);
            // children keys could be 'children', 'sub', 'child' etc.
            $childrenKeys = array('children', 'sub', 'child', 'list', 'categories', 'cat_list');
            foreach ($childrenKeys as $k) {
                if (isset($nodes[$k]) && is_array($nodes[$k])) {
                    foreach ($nodes[$k] as $c) $this->flattenCategoryNodes($c, $bg_cat_id, $out);
                }
            }
            return;
        }

        // Otherwise treat as list
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $bg_cat_id = isset($node['cat_id']) ? (string)$node['cat_id'] : (isset($node['id']) ? (string)$node['id'] : null);
            $name = isset($node['cat_name']) ? $node['cat_name'] : (isset($node['name']) ? $node['name'] : '');
            $parent = isset($node['parent_id']) ? (string)$node['parent_id'] : (isset($node['parent']) ? (string)$node['parent'] : $parent_bg_cat_id);
            $count = isset($node['product_count']) ? (int)$node['product_count'] : (isset($node['count']) ? (int)$node['count'] : 0);
            if ($bg_cat_id === null) {
                // maybe nested node without explicit id
                $this->flattenCategoryNodes($node, $parent_bg_cat_id, $out);
                continue;
            }
            $out[] = array('bg_cat_id' => $bg_cat_id, 'parent_bg_cat_id' => $parent, 'name' => $name, 'sort_order' => isset($node['sort_order']) ? (int)$node['sort_order'] : 0, 'product_count' => $count);
            // handle children if present
            $childrenKeys = array('children', 'sub', 'child', 'list', 'categories', 'cat_list');
            foreach ($childrenKeys as $k) {
                if (isset($node[$k]) && is_array($node[$k])) {
                    $this->flattenCategoryNodes($node[$k], $bg_cat_id, $out);
                }
            }
        }
    }

    /**
     * Build a nested tree from bg_category DB table and return as nested arrays.
     */
    protected function buildBgCategoryTreeFromDb() {
        $tbl = DB_PREFIX . "bg_category";
        $rows = $this->db->query("SELECT bgc_id, bg_cat_id, parent_id, name FROM `" . $tbl . "` ORDER BY sort_order, name")->rows;
        $byId = array();
        foreach ($rows as $r) {
            $id = (int)$r['bgc_id'];
            $byId[$id] = array('id' => $id, 'bg_cat_id' => $r['bg_cat_id'], 'name' => $r['name'], 'children' => array(), 'parent_id' => $r['parent_id']);
        }
        $tree = array();
        foreach ($byId as $id => &$node) {
            $p = (int)$node['parent_id'];
            if ($p && isset($byId[$p])) {
                $byId[$p]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);
        return $tree;
    }

    /**
     * Render nested HTML UL/LI for the category tree.
     */
    protected function renderBgCategoryTreeHtml(array $tree) {
        $html = '<ul class="bg-cat-tree" style="list-style:none;padding-left:10px;margin:0;">';
        $html .= $this->renderBgCategoryNodesHtml($tree);
        $html .= '</ul>';
        return $html;
    }

    protected function renderBgCategoryNodesHtml(array $nodes) {
        $s = '';
        foreach ($nodes as $node) {
            $hasChildren = !empty($node['children']);
            $s .= '<li data-id="' . (int)$node['id'] . '" class="' . ($hasChildren ? 'has-children' : 'leaf') . '" style="margin:4px 0;">';
            if ($hasChildren) {
                $s .= '<span class="bg-toggle" style="cursor:pointer;margin-right:6px;color:#888;">&#9656;</span>';
            } else {
                $s .= '<span style="display:inline-block;width:12px;margin-right:6px;"></span>';
            }
            $s .= '<span class="cat-label" style="cursor:pointer;">' . $this->htmlEscape($node['name']) . ' <small style="color:#666">(' . $this->htmlEscape($node['bg_cat_id']) . ')</small></span>';
            if ($hasChildren) {
                $s .= '<ul style="display:none; list-style:none; padding-left:16px; margin-top:6px;">';
                $s .= $this->renderBgCategoryNodesHtml($node['children']);
                $s .= '</ul>';
            }
            $s .= '</li>';
        }
        return $s;
    }

    protected function htmlEscape($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }

    /* ---------------------------------------------------------------------
       ADDED helper: Bold label:value segments and enforce spacing after semicolons.
       - This was missing and caused the fatal error. It's intentionally conservative:
         * Ensures a space after semicolons.
         * Wraps short label segments before a colon in <strong>...</strong>.
         * Operates on HTML strings via regex; avoids touching tags where possible.
    --------------------------------------------------------------------- */
    protected function boldLabelValueSegments($html) {
        if (empty($html) || !is_string($html)) return '';
        // Normalize semicolons to have exactly one space after them
        $html = preg_replace('/;\s*/', '; ', $html);

        // Attempt to bold simple "Label: " occurrences in text while avoiding tags.
        // We'll use a callback that skips matches that are inside HTML tags.
        // This regex finds sequences like "Label:" not containing '<' or '>' and limited length.
        $pattern = '/(?<=^|[>\s])([A-Za-z0-9\-\(\)\/&\s]{1,60}):\s*/u';

        $html = preg_replace_callback($pattern, function($m) {
            $label = trim($m[1]);
            if ($label === '') return $m[0];
            return '<strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>: ';
        }, $html);

        return $html;
    }

    /* ---------------------------------------------------------------------
       ADDED helper: apply POA quantities from a given poa_list to an existing product.
       - Non-destructive: respects allowPovOverwrite flag; otherwise only updates POV rows with quantity == 0.
       - Tries bg_poa_map first (if mapping exists) then text matching on option value names.
       - Will optionally persist bg_poa_map entries if module_banggood_import_allow_map_writes is enabled.
    --------------------------------------------------------------------- */
    protected function applyPoaQuantitiesToProduct($product_id, array $poa_list, $bg_id = '') {
        $product_id = (int)$product_id;
        if ($product_id <= 0 || empty($poa_list)) return array('updated' => 0, 'mapped' => 0, 'unmapped' => 0);

        $updated = 0; $mapped = 0; $unmapped = 0;
        $allow_overwrite = $this->allowPovOverwrite();
        $allow_map_writes = (bool)$this->config->get('module_banggood_import_allow_map_writes');

        // Build a map of poa_id => qty from poa_list
        $poa_qty = array();
        foreach ($poa_list as $group) {
            $values = array();
            if (!empty($group['option_values']) && is_array($group['option_values'])) $values = $group['option_values'];
            elseif (!empty($group['values']) && is_array($group['values'])) $values = $group['values'];
            foreach ($values as $v) {
                $poa_raw = '';
                if (isset($v['poa_id'])) $poa_raw = (string)$v['poa_id'];
                elseif (isset($v['poaId'])) $poa_raw = (string)$v['poaId'];
                $qty = $this->extractPoaQuantity(is_array($v) ? $v : array());
                if ($qty === null) {
                    // try direct keys
                    if (isset($v['stock']) && is_numeric($v['stock'])) $qty = (int)$v['stock'];
                    elseif (isset($v['quantity']) && is_numeric($v['quantity'])) $qty = (int)$v['quantity'];
                }
                if ($poa_raw !== '' && $qty !== null) {
                    $ids = $this->splitPoaIds($poa_raw);
                    foreach ($ids as $pid) {
                        if ($pid === '') continue;
                        if (!isset($poa_qty[$pid])) $poa_qty[$pid] = 0;
                        $poa_qty[$pid] += (int)$qty;
                    }
                } else {
                    // fallback: use name matching later
                    if (!empty($v['poa']) || !empty($v['poa_name']) || !empty($v['name'])) {
                        $poa_text = !empty($v['poa']) ? $v['poa'] : (!empty($v['poa_name']) ? $v['poa_name'] : $v['name']);
                        if ($poa_text && $qty !== null) {
                            // store under a pseudo id for text-match path
                            $poa_qty['text::' . substr($poa_text,0,200)] = (int)$qty;
                        }
                    }
                }
            }
        }

        if (empty($poa_qty)) return array('updated' => 0, 'mapped' => 0, 'unmapped' => 0);

        // First, update by bg_poa_map where mappings exist
        if (!empty($bg_id)) {
            $tbl = DB_PREFIX . 'bg_poa_map';
            $escaped_bg = $this->db->escape($bg_id);
            foreach ($poa_qty as $poa => $qty) {
                if (strpos($poa, 'text::') === 0) continue;
                $poa_esc = $this->db->escape($poa);
                $q = $this->db->query("SELECT DISTINCT option_value_id FROM `" . $tbl . "` WHERE bg_id = '" . $escaped_bg . "' AND poa_id = '" . $poa_esc . "' AND option_value_id IS NOT NULL AND option_value_id <> 0 LIMIT 1");
                if ($q && $q->num_rows) {
                    $optval = (int)$q->row['option_value_id'];
                    if ($optval) {
                        if ($allow_overwrite) {
                            $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = " . (int)$qty . " WHERE product_id = " . $product_id . " AND option_value_id = " . $optval);
                            $updated++;
                        } else {
                            $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = " . (int)$qty . " WHERE product_id = " . $product_id . " AND option_value_id = " . $optval . " AND quantity = 0");
                            $affected = $this->db->countAffected();
                            if ($affected) $updated += $affected;
                        }
                        $mapped++;
                        continue;
                    }
                }
            }
        }

        // Second, text-match by option_value_description.name
        foreach ($poa_qty as $poa => $qty) {
            if (strpos($poa, 'text::') === 0) {
                $poa_text = substr($poa, 6);
            } else {
                // try find by name exact or like using poa text if available in bg_poa_map poa_text
                $poa_text = '';
                if (!empty($poa)) {
                    if (!empty($bg_id)) {
                        $q = $this->db->query("SELECT poa_text FROM `" . DB_PREFIX . "bg_poa_map` WHERE bg_id = '" . $this->db->escape($bg_id) . "' AND poa_id = '" . $this->db->escape($poa) . "' LIMIT 1");
                        if ($q && $q->num_rows && !empty($q->row['poa_text'])) $poa_text = $q->row['poa_text'];
                    }
                }
                if ($poa_text === '') continue; // skip numeric poa ids already handled
            }

            $poa_text = trim($poa_text);
            if ($poa_text === '') {
                $unmapped++;
                continue;
            }

            // exact match
            $q2 = $this->db->query("SELECT ov.option_value_id FROM `" . DB_PREFIX . "option_value_description` ovd JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = ovd.option_value_id WHERE ovd.name = '" . $this->db->escape($poa_text) . "' LIMIT 1");
            if ($q2 && $q2->num_rows) {
                $optval = (int)$q2->row['option_value_id'];
                if ($allow_overwrite) {
                    $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = " . (int)$qty . " WHERE product_id = " . $product_id . " AND option_value_id = " . $optval);
                    $updated++;
                } else {
                    $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = " . (int)$qty . " WHERE product_id = " . $product_id . " AND option_value_id = " . $optval . " AND quantity = 0");
                    $affected = $this->db->countAffected();
                    if ($affected) $updated += $affected;
                }
                $mapped++;
                // persist mapping if allowed and bg_id present
                if ($allow_map_writes && !empty($bg_id)) {
                    $tbl = DB_PREFIX . 'bg_poa_map';
                    $this->db->query("INSERT INTO `" . $tbl . "` (bg_id, poa_id, option_value_id) VALUES ('" . $this->db->escape($bg_id) . "', '" . $this->db->escape($poa_text) . "', '" . (int)$optval . "') ON DUPLICATE KEY UPDATE option_value_id = '" . (int)$optval . "'");
                }
                continue;
            }

            // like match
            $q3 = $this->db->query("SELECT ov.option_value_id FROM `" . DB_PREFIX . "option_value_description` ovd JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = ovd.option_value_id WHERE ovd.name LIKE '%" . $this->db->escape($poa_text) . "%' LIMIT 1");
            if ($q3 && $q3->num_rows) {
                $optval = (int)$q3->row['option_value_id'];
                if ($allow_overwrite) {
                    $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = " . (int)$qty . " WHERE product_id = " . $product_id . " AND option_value_id = " . $optval);
                    $updated++;
                } else {
                    $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = " . (int)$qty . " WHERE product_id = " . $product_id . " AND option_value_id = " . $optval . " AND quantity = 0");
                    $affected = $this->db->countAffected();
                    if ($affected) $updated += $affected;
                }
                $mapped++;
                if ($allow_map_writes && !empty($bg_id)) {
                    $tbl = DB_PREFIX . 'bg_poa_map';
                    $this->db->query("INSERT INTO `" . $tbl . "` (bg_id, poa_id, option_value_id) VALUES ('" . $this->db->escape($bg_id) . "', '" . $this->db->escape($poa_text) . "', '" . (int)$optval . "') ON DUPLICATE KEY UPDATE option_value_id = '" . (int)$optval . "'");
                }
                continue;
            }

            $unmapped++;
        }

        return array('updated' => $updated, 'mapped' => $mapped, 'unmapped' => $unmapped);
    }

    /* -------------------------
       NEW FUNCTIONS: persistProductVariant & upsertProductVariantsFromBgPoa
       - These insert or update rows in oc_product_variant based on per_poa aggregates / bg_poa_map.
       - They do not remove other functions and are non-destructive (update or insert only).
       - Updated to produce combination-level rows matching the bg_stock_check output (option_key as "563|571|574", option_text "Size: S / Color: Green / Ship From: CN", sku "BG-1960208-563-571-574").
       ------------------------- */

    /**
     * Convert product_option_value IDs (POV) to option_value_ids (OV).
     */
    protected function convertPovIdsToOvIds(array $ids) {
        $out = [];
        $ids = array_map('intval', array_filter($ids));
        if (empty($ids)) return $out;
        $in = implode(',', $ids);
        $q = $this->db->query("SELECT product_option_value_id, option_value_id FROM `" . DB_PREFIX . "product_option_value` WHERE product_option_value_id IN (" . $in . ")");
        if ($q->num_rows) {
            foreach ($q->rows as $r) {
                if (!empty($r['option_value_id'])) $out[] = (int)$r['option_value_id'];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Upsert a single product_variant row (insert if missing, update if exists).
     */
    protected function persistProductVariant($product_id, $option_key, $option_text, $sku, $quantity = 0, $price = 0.0, $bg_poa_ids = null, $bg_id = null, $stock_status_id = null, $stock_status_token = null) {
        $product_id = (int)$product_id;
        $quantity = (int)$quantity;
        $price = (float)$price;
        $sku_sql = $this->db->escape((string)$sku);
        $option_key_raw = (string)$option_key;
        $option_key_sql = $this->db->escape($option_key_raw);
        $option_text_sql = $this->db->escape((string)$option_text);

        // allow explicit NULL for bg_poa_ids/bg_id (we want NULL to match bg_stock_check output)
        $bg_poa_ids_sql = ($bg_poa_ids === null) ? 'NULL' : ("'" . $this->db->escape((string)$bg_poa_ids) . "'");
        $bg_id_sql = ($bg_id === null) ? 'NULL' : ("'" . $this->db->escape((string)$bg_id) . "'");
        // Treat empty string as NULL
        if ($stock_status_token !== null && trim((string)$stock_status_token) === '') $stock_status_token = null;
        $stock_status_token_sql = ($stock_status_token === null) ? 'NULL' : ("'" . $this->db->escape((string)$stock_status_token) . "'");
        $stock_status_id_sql = ($stock_status_id === null) ? 'NULL' : (int)$stock_status_id;

        // Normalize keys for matching (handle commas/pipes/whitespace and ordering differences)
        $parts = preg_split('/[,\|]+/', trim($option_key_raw));
        $parts = array_values(array_filter(array_map('trim', $parts), function($v){ return $v !== ''; }));
        $parts_int = array();
        foreach ($parts as $p) {
            if (preg_match('/^\d+$/', $p)) $parts_int[] = (int)$p;
        }
        $parts_int = array_values(array_unique($parts_int));
        $norm_key_commas = $parts_int ? implode(',', $parts_int) : $option_key_raw;
        $norm_key_pipes  = $parts_int ? implode('|', $parts_int) : $option_key_raw;
        $sorted_int = $parts_int;
        sort($sorted_int, SORT_NUMERIC);
        $sorted_key_commas = $sorted_int ? implode(',', $sorted_int) : $norm_key_commas;

        // Find existing variant by product_id + option_key (try multiple forms) OR by SKU
        $exists_q = $this->db->query(
            "SELECT variant_id FROM `" . DB_PREFIX . "product_variant`
             WHERE product_id = " . $product_id . "
               AND option_key IN (
                 '" . $this->db->escape($option_key_raw) . "',
                 '" . $this->db->escape($norm_key_commas) . "',
                 '" . $this->db->escape($norm_key_pipes) . "',
                 '" . $this->db->escape($sorted_key_commas) . "'
               )
             LIMIT 1"
        );
        if ($exists_q && $exists_q->num_rows) {
            $variant_id = (int)$exists_q->row['variant_id'];
            // Update
            $update_sql = "UPDATE `" . DB_PREFIX . "product_variant` SET
                sku = '" . $sku_sql . "',
                option_key = '" . $this->db->escape($norm_key_commas) . "',
                option_text = '" . $option_text_sql . "',
                quantity = " . $quantity . ",
                price = '" . (float)$price . "',
                bg_poa_ids = " . $bg_poa_ids_sql . ",
                bg_id = " . $bg_id_sql . ",
                stock_status_token = " . $stock_status_token_sql . ",
                stock_status_id = " . ($stock_status_id_sql === 'NULL' ? 'NULL' : $stock_status_id_sql) . ",
                date_modified = NOW()
             WHERE variant_id = " . $variant_id;
            $this->db->query($update_sql);
            return $variant_id;
        }

        // Otherwise try by SKU (in case option_key changed)
        if ($sku_sql !== '') {
            $exists_sku_q = $this->db->query("SELECT variant_id FROM `" . DB_PREFIX . "product_variant` WHERE sku = '" . $sku_sql . "' AND product_id = " . $product_id . " LIMIT 1");
            if ($exists_sku_q && $exists_sku_q->num_rows) {
                $variant_id = (int)$exists_sku_q->row['variant_id'];
                $update_sql = "UPDATE `" . DB_PREFIX . "product_variant` SET
                    option_key = '" . $this->db->escape($norm_key_commas) . "',
                    option_text = '" . $option_text_sql . "',
                    quantity = " . $quantity . ",
                    price = '" . (float)$price . "',
                    bg_poa_ids = " . $bg_poa_ids_sql . ",
                    bg_id = " . $bg_id_sql . ",
                    stock_status_token = " . $stock_status_token_sql . ",
                    stock_status_id = " . ($stock_status_id_sql === 'NULL' ? 'NULL' : $stock_status_id_sql) . ",
                    date_modified = NOW()
                 WHERE variant_id = " . $variant_id;
                $this->db->query($update_sql);
                return $variant_id;
            }
        }

        // Last-resort: order-insensitive match by set equality (same ids, different order)
        if (!empty($sorted_int)) {
            $cand = $this->db->query(
                "SELECT variant_id, option_key FROM `" . DB_PREFIX . "product_variant`
                 WHERE product_id = " . $product_id
            );
            if ($cand && $cand->num_rows) {
                foreach ($cand->rows as $r) {
                    $ok = isset($r['option_key']) ? (string)$r['option_key'] : '';
                    if ($ok === '') continue;
                    $p = preg_split('/[,\|]+/', trim($ok));
                    $p = array_values(array_filter(array_map('trim', $p), function($v){ return $v !== ''; }));
                    $pi = array();
                    foreach ($p as $x) if (preg_match('/^\d+$/', $x)) $pi[] = (int)$x;
                    $pi = array_values(array_unique($pi));
                    sort($pi, SORT_NUMERIC);
                    if ($pi === $sorted_int) {
                        $variant_id = (int)$r['variant_id'];
                        $this->db->query(
                            "UPDATE `" . DB_PREFIX . "product_variant` SET
                                sku = '" . $sku_sql . "',
                                option_key = '" . $this->db->escape($norm_key_commas) . "',
                                option_text = '" . $option_text_sql . "',
                                quantity = " . $quantity . ",
                                price = '" . (float)$price . "',
                                bg_poa_ids = " . $bg_poa_ids_sql . ",
                                bg_id = " . $bg_id_sql . ",
                                stock_status_token = " . $stock_status_token_sql . ",
                                stock_status_id = " . ($stock_status_id_sql === 'NULL' ? 'NULL' : $stock_status_id_sql) . ",
                                date_modified = NOW()
                             WHERE variant_id = " . $variant_id
                        );
                        return $variant_id;
                    }
                }
            }
        }

        // Insert new
        $insert_sql = "INSERT INTO `" . DB_PREFIX . "product_variant`
          (product_id, sku, option_key, option_text, quantity, stock_status_token, stock_status_id, price, bg_poa_ids, bg_id, date_modified)
         VALUES (" . $product_id . ", '" . $sku_sql . "', '" . $this->db->escape($norm_key_commas) . "', '" . $option_text_sql . "', " . $quantity . ", " . $stock_status_token_sql . ", " . ($stock_status_id_sql === 'NULL' ? 'NULL' : $stock_status_id_sql) . ", '" . (float)$price . "', " . $bg_poa_ids_sql . ", " . $bg_id_sql . ", NOW())";
        $this->db->query($insert_sql);
        return $this->db->getLastId();
    }

    /**
     * Build product_variant rows from per_poa aggregates and bg_poa_map.
     *
     * This function prefers to use the $per_poa array (from API getStocks aggregation) to create
     * combination-level variants that match the bg_stock_check representation:
     *  - option_key: pipe-separated option_value_ids (e.g. "563|571|574")
     *  - option_text: human readable joined labels (e.g. "Size: S / Color: Green / Ship From: CN")
     *  - sku: base_model + '-' + joined option_value_ids (e.g. "BG-1960208-563-571-574")
     *
     * It will attempt to resolve option_value_ids using the bg_poa_map when necessary.
     * Does not delete existing variants (insert or update only).
     */
    protected function upsertProductVariantsFromBgPoa($bg_id, $product_id, array $stocks) {
    if (empty($product_id)) return;

    // Ensure existing variants get bg_id populated (some themes filter by bg_id)
    try {
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "product_variant`
             SET bg_id = '" . $this->db->escape((string)$bg_id) . "'
             WHERE product_id = " . (int)$product_id . " AND (bg_id IS NULL OR bg_id = '')"
        );
    } catch (Exception $e) {}

    $language_id = (int)$this->config->get('config_language_id') ? (int)$this->config->get('config_language_id') : 1;
    $base_model = '';
    $prod_q = $this->db->query("SELECT model FROM `" . DB_PREFIX . "product` WHERE product_id = " . (int)$product_id . " LIMIT 1");
    if ($prod_q && isset($prod_q->row['model']) && $prod_q->row['model'] !== '') $base_model = $prod_q->row['model'];

    // Best-effort: fetch product detail once to read warehouse_list and poa_list
    $config = $this->getBanggoodConfig();
    $product_detail = array();
    try {
        $product_detail = $this->fetchProductDetail($config, $bg_id);
    } catch (Exception $e) {
        $product_detail = array();
    }

    $this->ensureBgPoaMapTableExists();

    // Build a POA->option_value_id map from GetProductInfo (does not require bg_poa_map writes)
    $poa_to_ov = array();
    try {
        $poa_list = array();
        if (is_array($product_detail) && isset($product_detail['poa_list']) && is_array($product_detail['poa_list'])) {
            $poa_list = $product_detail['poa_list'];
        } elseif (is_array($product_detail) && isset($product_detail['data']['poa_list']) && is_array($product_detail['data']['poa_list'])) {
            $poa_list = $product_detail['data']['poa_list'];
        }
        if (!empty($poa_list)) {
            foreach ($poa_list as $group) {
                if (!is_array($group)) continue;
                $option_name = isset($group['option_name']) ? (string)$group['option_name'] : (isset($group['name']) ? (string)$group['name'] : '');
                $option_name = $this->normalizeImportedOptionName($option_name);
                if ($option_name === '') continue;
                $option_id = $this->getOptionIdByName($option_name);
                if (!$option_id) continue;
                $values = array();
                if (isset($group['option_values']) && is_array($group['option_values'])) $values = $group['option_values'];
                elseif (isset($group['values']) && is_array($group['values'])) $values = $group['values'];
                foreach ($values as $val) {
                    if (!is_array($val)) continue;
                    $poa_id_raw = isset($val['poa_id']) ? (string)$val['poa_id'] : (isset($val['poaId']) ? (string)$val['poaId'] : '');
                    $value_name = isset($val['poa_name']) ? (string)$val['poa_name'] : (isset($val['name']) ? (string)$val['name'] : '');
                    if ($poa_id_raw === '' || $value_name === '') continue;
                    $ov_id = $this->getOptionValueIdByName($option_id, $value_name);
                    if (!$ov_id) continue;
                    foreach ($this->splitPoaIds($poa_id_raw) as $pid) {
                        if ($pid === '') continue;
                        $poa_to_ov[$pid] = (int)$ov_id;
                    }
                }
            }
        }
    } catch (Exception $e) {
        $poa_to_ov = array();
    }

    $variant_prices = array();

    // Helper: resolve "Ship From" product_option_value_id for a given warehouse key/label
    $resolveShipFromPovId = function($warehouse_key) use ($product_id, $language_id) {
        $warehouse_key = trim((string)$warehouse_key);
        if ($warehouse_key === '') return 0;
        // Find the "Ship From" option_id
        $qOpt = $this->db->query(
            "SELECT od.option_id
             FROM `" . DB_PREFIX . "option_description` od
             WHERE od.language_id = " . (int)$language_id . " AND od.name = 'Ship From'
             LIMIT 1"
        );
        if (!$qOpt || !$qOpt->num_rows) return 0;
        $ship_option_id = (int)$qOpt->row['option_id'];
        if ($ship_option_id <= 0) return 0;

        // Find option_value_id by name (case-insensitive match on warehouse key)
        $qOv = $this->db->query(
            "SELECT ov.option_value_id
             FROM `" . DB_PREFIX . "option_value` ov
             JOIN `" . DB_PREFIX . "option_value_description` ovd ON ovd.option_value_id = ov.option_value_id AND ovd.language_id = " . (int)$language_id . "
             WHERE ov.option_id = " . (int)$ship_option_id . "
               AND (LOWER(ovd.name) = LOWER('" . $this->db->escape($warehouse_key) . "')
                    OR LOWER(ovd.name) LIKE LOWER('%" . $this->db->escape($warehouse_key) . "%'))
             LIMIT 1"
        );
        if (!$qOv || !$qOv->num_rows) return 0;
        $ov_id = (int)$qOv->row['option_value_id'];
        if ($ov_id <= 0) return 0;

        // Map to product_option_value_id for this product
        $qPov = $this->db->query(
            "SELECT product_option_value_id
             FROM `" . DB_PREFIX . "product_option_value`
             WHERE product_id = " . (int)$product_id . " AND option_value_id = " . (int)$ov_id . "
             LIMIT 1"
        );
        if ($qPov && $qPov->num_rows) return (int)$qPov->row['product_option_value_id'];
        return 0;
    };

    foreach ($stocks as $row) {
        // combination-level stock row -> poa_id may contain multiple ids
        $stock_status_token = null;
        if (isset($row['stock_msg']) && $row['stock_msg'] !== '') $stock_status_token = (string)$row['stock_msg'];
        elseif (isset($row['stocks_msg']) && $row['stocks_msg'] !== '') $stock_status_token = (string)$row['stocks_msg'];
        $stock_status_token = $this->normalizeStockStatusToken($stock_status_token);

        $poa_raw = '';
        if (isset($row['poa_id'])) $poa_raw = (string)$row['poa_id'];
        elseif (isset($row['poa'])) $poa_raw = (string)$row['poa'];
        if (trim($poa_raw) === '') continue;

        // split tokens (allow comma, pipe, semicolon, whitespace)
        $tokens = preg_split('/[,\|\s;]+/', trim($poa_raw));
        $tokens = array_filter(array_map('trim', $tokens), function($v){ return $v !== ''; });
        if (empty($tokens)) continue;

        // map tokens -> option_value_id using:
        // 1) GetProductInfo-derived poa_to_ov map (preferred)
        // 2) bg_poa_map (if present)
        // 3) name matching fallback
        $ov_ids = array();
        foreach ($tokens as $t) {
            if (isset($poa_to_ov[$t]) && (int)$poa_to_ov[$t] > 0) {
                $ov_ids[] = (int)$poa_to_ov[$t];
                continue;
            }
            $mq = $this->db->query(
                "SELECT DISTINCT option_value_id FROM `" . DB_PREFIX . "bg_poa_map`
                 WHERE bg_id = '" . $this->db->escape((string)$bg_id) . "'
                   AND poa_id = '" . $this->db->escape((string)$t) . "'
                 LIMIT 1"
            );
            if ($mq && $mq->num_rows) {
                $ov_ids[] = (int)$mq->row['option_value_id'];
            } else {
                // fallback to matching option_value_description.name
                $mq2 = $this->db->query(
                    "SELECT ov.option_value_id FROM `" . DB_PREFIX . "option_value_description` ovd
                     JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = ovd.option_value_id
                     WHERE ovd.name = '" . $this->db->escape($t) . "' LIMIT 1"
                );
                if ($mq2 && $mq2->num_rows) $ov_ids[] = (int)$mq2->row['option_value_id'];
            }
        }

        // Convert option_value_ids -> product_option_value_ids for this product.
        // IMPORTANT: existing oc_product_variant.option_key uses product_option_value_id values.
        $pov_ids = array();
        if (!empty($ov_ids)) {
            $ov_ids = array_values(array_unique(array_map('intval', $ov_ids)));
            $qmap = $this->db->query(
                "SELECT product_option_value_id, option_value_id
                 FROM `" . DB_PREFIX . "product_option_value`
                 WHERE product_id = " . (int)$product_id . "
                   AND option_value_id IN (" . implode(',', $ov_ids) . ")"
            );
            $ov_to_pov = array();
            if ($qmap && $qmap->num_rows) {
                foreach ($qmap->rows as $r) {
                    $ov_to_pov[(int)$r['option_value_id']] = (int)$r['product_option_value_id'];
                }
            }
            foreach ($ov_ids as $ov) {
                if (isset($ov_to_pov[$ov]) && (int)$ov_to_pov[$ov] > 0) {
                    $pov_ids[] = (int)$ov_to_pov[$ov];
                }
            }
        }

        // Include Ship From (warehouse) selection if present so front-end variant lookups match.
        $warehouse_key = isset($row['warehouse']) ? (string)$row['warehouse'] : '';
        $ship_pov_id = 0;
        if ($warehouse_key !== '') {
            $ship_pov_id = (int)$resolveShipFromPovId($warehouse_key);
            if ($ship_pov_id > 0) $pov_ids[] = $ship_pov_id;
        }

        // If no mapping found, persist a fallback variant using raw strings
        if (empty($pov_ids)) {
            $option_key = $poa_raw;
            $option_text = isset($row['poa_text']) ? (string)$row['poa_text'] : $poa_raw;
            $sku = ($base_model ? $base_model : (self::PRODUCT_CODE_PREFIX . $this->db->escape((string)$bg_id))) . '-' . preg_replace('/[^0-9A-Za-z\-_]/', '-', $option_key);
            $quantity = isset($row['stock']) ? (int)$row['stock'] : 0;

            // Try GetProductPrice for this combination as authoritative
            $variant_price = 0.0;
            try {
                $params = array(
                    'product_id' => (string)$bg_id,
                    'poa_id'     => (string)$poa_raw,
                    'warehouse'  => (isset($row['warehouse']) ? $row['warehouse'] : ''),
                    'currency'   => $config['currency'],
                    'lang'       => $config['lang']
                );
                $price_resp = $this->apiRequest($config, 'product/GetProductPrice', 'GET', $params);
                if (is_array($price_resp) && isset($price_resp['productPrice']) && is_array($price_resp['productPrice']) && !empty($price_resp['productPrice'])) {
                    $first = reset($price_resp['productPrice']);
                    if (isset($first['price'])) $variant_price = (float)$first['price'];
                }
            } catch (Exception $e) {
                $variant_price = 0.0;
            }

            $this->persistProductVariant($product_id, $option_key, $option_text, $sku, $quantity, $variant_price, (string)$poa_raw, (string)$bg_id, null, $stock_status_token);
            $variant_prices[] = (float)$variant_price;
            continue;
        }

        // normalize/unique pov ids and order by product_option_id so option order matches UI
        $pov_ids = array_values(array_unique(array_map('intval', $pov_ids)));
        if (count($pov_ids) > 1) {
            $sql = "SELECT pov.product_option_value_id, pov.product_option_id, ov.option_id
                    FROM `" . DB_PREFIX . "product_option_value` pov
                    JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = pov.option_value_id
                    WHERE pov.product_id = " . (int)$product_id . " AND pov.product_option_value_id IN (" . implode(',', $pov_ids) . ")";
            $rows_db = $this->db->query($sql)->rows;
            $map = array(); // pov_id => (product_option_id, option_id)
            foreach ($rows_db as $r) {
                $map[(int)$r['product_option_value_id']] = array(
                    'poid' => isset($r['product_option_id']) ? (int)$r['product_option_id'] : 0,
                    'oid'  => isset($r['option_id']) ? (int)$r['option_id'] : 0
                );
            }
            usort($pov_ids, function($a, $b) use ($map) {
                $pa = isset($map[$a]) ? (int)$map[$a]['poid'] : 0;
                $pb = isset($map[$b]) ? (int)$map[$b]['poid'] : 0;
                if ($pa === $pb) {
                    $oa = isset($map[$a]) ? (int)$map[$a]['oid'] : 0;
                    $ob = isset($map[$b]) ? (int)$map[$b]['oid'] : 0;
                    if ($oa === $ob) return $a - $b;
                    return $oa - $ob;
                }
                return $pa - $pb;
            });
        }

        // build option_key and option_text
        // Keep option_key comma-separated (matches existing oc_product_variant.option_key rows)
        $option_key = implode(',', $pov_ids);
        $text_parts = array();
        foreach ($pov_ids as $pov_id) {
            $ovq = $this->db->query(
                "SELECT ov.option_id, ovd.name AS value_name, od.name AS option_name
                 FROM `" . DB_PREFIX . "product_option_value` pov
                 JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = pov.option_value_id
                 LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd ON ov.option_value_id = ovd.option_value_id AND ovd.language_id = " . (int)$language_id . "
                 LEFT JOIN `" . DB_PREFIX . "option_description` od ON ov.option_id = od.option_id AND od.language_id = " . (int)$language_id . "
                 WHERE pov.product_option_value_id = " . (int)$pov_id . " AND pov.product_id = " . (int)$product_id . " LIMIT 1"
            );
            if ($ovq && $ovq->num_rows) {
                $opt_name = isset($ovq->row['option_name']) ? trim($ovq->row['option_name']) : '';
                $val_name = isset($ovq->row['value_name']) ? trim($ovq->row['value_name']) : '';
                $text_parts[] = ($opt_name !== '' ? $opt_name . ': ' . $val_name : $val_name);
            } else {
                $text_parts[] = (string)$pov_id;
            }
        }
        $option_text = implode(' / ', $text_parts);
        $sku_suffix = implode('-', array_map('strval', $pov_ids));
        $sku = ($base_model ? $base_model : (self::PRODUCT_CODE_PREFIX . $this->db->escape((string)$bg_id))) . '-' . $sku_suffix;
        $quantity = isset($row['stock']) ? (int)$row['stock'] : 0;

        // First, try product/GetProductPrice for this exact combination (authoritative)
        $variant_price = null;
        try {
            $params = array(
                'product_id' => (string)$bg_id,
                'poa_id'     => (string)$poa_raw,
                'warehouse'  => (isset($row['warehouse']) ? $row['warehouse'] : ''),
                'currency'   => $config['currency'],
                'lang'       => $config['lang']
            );
            $price_resp = $this->apiRequest($config, 'product/GetProductPrice', 'GET', $params);
            if (is_array($price_resp) && isset($price_resp['productPrice']) && is_array($price_resp['productPrice']) && !empty($price_resp['productPrice'])) {
                $first = reset($price_resp['productPrice']);
                if (isset($first['price'])) $variant_price = (float)$first['price'];
            }
        } catch (Exception $e) {
            $variant_price = null;
        }

        // If API did not return price, compute from warehouse + poa sums (legacy fallback)
        if ($variant_price === null) {
            // Determine warehouse base price
            $warehouse_key = isset($row['warehouse']) ? (string)$row['warehouse'] : '';
            $warehouse_price = null;
            if (!empty($product_detail) && isset($product_detail['warehouse_list']) && is_array($product_detail['warehouse_list'])) {
                foreach ($product_detail['warehouse_list'] as $wh) {
                    $wh_key = isset($wh['warehouse']) ? (string)$wh['warehouse'] : (isset($wh['site']) ? (string)$wh['site'] : '');
                    $wh_label = isset($wh['warehouse_name']) ? (string)$wh['warehouse_name'] : '';
                    if ($warehouse_key !== '' && (strcasecmp($wh_key, $warehouse_key) === 0 || stripos($wh_label, $warehouse_key) !== false || stripos($warehouse_key, $wh_label) !== false)) {
                        if (isset($wh['warehouse_price'])) { $warehouse_price = (float)$wh['warehouse_price']; break; }
                    }
                }
                if ($warehouse_price === null && isset($product_detail['warehouse_list'][0]['warehouse_price'])) {
                    $warehouse_price = (float)$product_detail['warehouse_list'][0]['warehouse_price'];
                }
            }
            if ($warehouse_price === null) {
                $qwb = $this->db->query(
                    "SELECT MIN(COALESCE(product_price,0) - COALESCE(price_modifier,0)) AS wb
                     FROM `" . DB_PREFIX . "bg_poa_warehouse_map`
                     WHERE bg_id = '" . $this->db->escape((string)$bg_id) . "' AND warehouse_key = '" . $this->db->escape($warehouse_key) . "'"
                );
                if ($qwb && isset($qwb->row['wb']) && $qwb->row['wb'] !== null) $warehouse_price = (float)$qwb->row['wb'];
            }
            if ($warehouse_price === null) {
                $cb = $this->computeBasePriceFromMaps($bg_id);
                if ($cb !== null) $warehouse_price = (float)$cb;
            }
            if ($warehouse_price === null) $warehouse_price = 0.0;

            // Sum poa prices
            $sum_poa_price = 0.0;
            foreach ($ov_ids as $ov) {
                $poa_price_val = null;
                $qpp = $this->db->query(
                    "SELECT MIN(COALESCE(poa_price,0)) AS poa_price
                     FROM `" . DB_PREFIX . "bg_poa_map`
                     WHERE bg_id = '" . $this->db->escape((string)$bg_id) . "' AND option_value_id = " . (int)$ov
                );
                if ($qpp && isset($qpp->row['poa_price']) && $qpp->row['poa_price'] !== null && (float)$qpp->row['poa_price'] != 0.0) {
                    $poa_price_val = (float)$qpp->row['poa_price'];
                }

                if ($poa_price_val === null && !empty($product_detail) && isset($product_detail['poa_list']) && is_array($product_detail['poa_list'])) {
                    foreach ($product_detail['poa_list'] as $group) {
                        $values = array();
                        if (!empty($group['option_values']) && is_array($group['option_values'])) $values = $group['option_values'];
                        foreach ($values as $v) {
                            $v_poa_id = isset($v['poa_id']) ? (string)$v['poa_id'] : (isset($v['poa']) ? (string)$v['poa'] : '');
                            $v_name = isset($v['poa_name']) ? (string)$v['poa_name'] : (isset($v['name']) ? (string)$v['name'] : '');
                            if ($v_poa_id !== '') {
                                $mq = $this->db->query("SELECT option_value_id FROM `" . DB_PREFIX . "bg_poa_map` WHERE bg_id = '" . $this->db->escape((string)$bg_id) . "' AND poa_id = '" . $this->db->escape($v_poa_id) . "' LIMIT 1");
                                if ($mq && $mq->num_rows && (int)$mq->row['option_value_id'] === (int)$ov) {
                                    if (isset($v['poa_price']) && $v['poa_price'] !== '') { $poa_price_val = (float)$v['poa_price']; break 3; }
                                    if (isset($v['price']) && $v['price'] !== '') { $poa_price_val = (float)$v['price']; break 3; }
                                }
                            }
                            if ($v_name !== '') {
                                $ovn = $this->db->query("SELECT ov.option_value_id FROM `" . DB_PREFIX . "option_value_description` ovd JOIN `" . DB_PREFIX . "option_value` ov ON ov.option_value_id = ovd.option_value_id WHERE ovd.name = '" . $this->db->escape($v_name) . "' AND ov.option_value_id = " . (int)$ov . " LIMIT 1");
                                if ($ovn && $ovn->num_rows) {
                                    if (isset($v['poa_price']) && $v['poa_price'] !== '') { $poa_price_val = (float)$v['poa_price']; break 3; }
                                    if (isset($v['price']) && $v['price'] !== '') { $poa_price_val = (float)$v['price']; break 3; }
                                }
                            }
                        }
                    }
                }

                if ($poa_price_val === null) $poa_price_val = 0.0;
                $sum_poa_price += (float)$poa_price_val;

                if ((float)$poa_price_val != 0.0) {
                    try {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET price = '" . (float)$poa_price_val . "', price_prefix = '+' WHERE product_id = " . (int)$product_id . " AND option_value_id = " . (int)$ov);
                    } catch (Exception $e) {}
                }
            }

            $variant_price = (float)$warehouse_price + (float)$sum_poa_price;
        }

        // Persist variant and record price (include bg_id + bg_poa_ids)
        $this->persistProductVariant((int)$product_id, $option_key, $option_text, $sku, $quantity, (float)$variant_price, (string)$poa_raw, (string)$bg_id, null, $stock_status_token);
        // Also persist a legacy key variant without Ship From (some themes don't include warehouse in the key)
        if ($ship_pov_id > 0) {
            $pov_ids_no_ship = array_values(array_filter($pov_ids, function($x) use ($ship_pov_id){ return (int)$x !== (int)$ship_pov_id; }));
            if (!empty($pov_ids_no_ship)) {
                $opt_key_no_ship = implode(',', $pov_ids_no_ship);
                $sku_no_ship = ($base_model ? $base_model : (self::PRODUCT_CODE_PREFIX . $this->db->escape((string)$bg_id))) . '-' . implode('-', array_map('strval', $pov_ids_no_ship));
                $this->persistProductVariant((int)$product_id, $opt_key_no_ship, $option_text, $sku_no_ship, $quantity, (float)$variant_price, (string)$poa_raw, (string)$bg_id, null, $stock_status_token);
            }
        }
        $variant_prices[] = (float)$variant_price;
    }

    // Update product base price to minimum variant price if we determined any variant prices
    if (!empty($variant_prices)) {
        $min_price = min($variant_prices);
        if ($min_price > 0) {
            try {
                $this->db->query("UPDATE `" . DB_PREFIX . "product` SET price = '" . (float)$min_price . "' WHERE product_id = " . (int)$product_id);
            } catch (Exception $e) {}
        }
    }
}
		
}
?>
