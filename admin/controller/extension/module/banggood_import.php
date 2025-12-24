<?php
class ControllerExtensionModuleBanggoodImport extends Controller {
    private $error = array();
    // Bump this when debugging live deployments/caches
    const BG_IMPORT_BUILD = '2025-12-23-queue-fix-3';

    /**
     * Render the persisted fetched-products list directly from DB.
     * Returns array: [html, recent_count, total_count]
     */
    protected function renderFetchedProductsList($limit = 200) {
        $rp_html = '';
        $recent_count = 0;
        $total_count = 0;

        $tbl = $this->getFetchedProductsTableName();
        $q = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($tbl) . "'");
        if (!$q->num_rows) {
            $rp_html = '<div class="text-muted">Fetched-products table not found: ' . htmlspecialchars($tbl, ENT_QUOTES, 'UTF-8') . '</div>';
            return array($rp_html, 0, 0);
        }

        // IMPORTANT: do not escape table identifiers inside backticks
        $qc = $this->db->query("SELECT COUNT(*) AS cnt FROM `" . $tbl . "`");
        $total_count = isset($qc->row['cnt']) ? (int)$qc->row['cnt'] : 0;

        $importedCol = $this->getFetchedProductsImportedAtColumnNameController();
        $updatedCol = $this->getFetchedProductsUpdatedAtColumnNameController();
        $selectExtra = '';
        if ($importedCol) $selectExtra .= ", `" . $importedCol . "` AS imported_at";
        else $selectExtra .= ", NULL AS imported_at";
        if ($updatedCol) $selectExtra .= ", `" . $updatedCol . "` AS updated_at";
        else $selectExtra .= ", NULL AS updated_at";

        $qr = $this->db->query(
            "SELECT `bg_product_id`, `cat_id`, `name`, `img`, `meta_desc`, `fetched_at`, `status`, `attempts`" . $selectExtra . "
             FROM `" . $tbl . "`
             ORDER BY `fetched_at` DESC, `id` DESC
             LIMIT " . (int)$limit
        );
        $recent = $qr->rows;
        $recent_count = is_array($recent) ? count($recent) : 0;

        if ($recent_count === 0) {
            $rp_html = '<div class="text-muted">No fetched products yet. Use "Fetch 10" to populate the list.</div>';
            return array($rp_html, 0, $total_count);
        }

        $rp_html .= '<div style="font-size:12px;color:#666;padding:6px 8px;border-bottom:1px solid #eee;">Showing ' . (int)$recent_count . ' (latest) of ' . (int)$total_count . ' persisted products</div>';
        $rp_html .= '<div class="bg-fetched-list" style="max-height:520px;overflow:auto;">';

        foreach ($recent as $row) {
            $img  = isset($row['img']) ? $row['img'] : '';
            $name = isset($row['name']) && $row['name'] !== '' ? $row['name'] : (isset($row['bg_product_id']) ? $row['bg_product_id'] : '');
            $meta = isset($row['meta_desc']) ? $row['meta_desc'] : '';
            $bgid = isset($row['bg_product_id']) ? $row['bg_product_id'] : '';
            $cat  = isset($row['cat_id']) ? $row['cat_id'] : '';
            $fetched_at = isset($row['fetched_at']) && $row['fetched_at'] ? date('Y-m-d', strtotime($row['fetched_at'])) : '';
            $status = isset($row['status']) ? strtolower((string)$row['status']) : '';
            $statusLabel = $status !== '' ? strtoupper($status) : '';
            $attempts = isset($row['attempts']) ? (int)$row['attempts'] : 0;
            $imported_at = isset($row['imported_at']) && $row['imported_at'] ? (string)$row['imported_at'] : null;
            $updated_at = isset($row['updated_at']) && $row['updated_at'] ? (string)$row['updated_at'] : null;

            // Real colors requested
            $color_green = '#28a745';
            $color_red = '#dc3545';
            $color_gray = '#6c757d';
            $color_blue = '#007bff';

            $badgeBg = $color_gray;
            if ($status === 'imported') $badgeBg = $color_green;
            elseif ($status === 'updated') $badgeBg = ($updated_at ? $color_green : $color_red);
            elseif ($status === 'error') $badgeBg = $color_red;
            elseif ($status === 'processing') $badgeBg = $color_blue;
            elseif ($status === 'pending') $badgeBg = $color_gray;

            $rp_html .= '<div class="bg-compact-row" style="display:flex;align-items:center;padding:6px 8px;border-bottom:1px solid #f1f1f1;font-size:13px;white-space:nowrap;">';
            $rp_html .= '<div style="flex:0 0 36px;margin-right:8px;">';
            $rp_html .= '<img src="' . htmlspecialchars($img ?: 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', ENT_QUOTES, 'UTF-8') . '" alt="" style="width:36px;height:36px;object-fit:cover;border:1px solid #eaeaea;border-radius:3px"/>';
            $rp_html .= '</div>';

            $rp_html .= '<div style="flex:1 1 auto;min-width:0;overflow:hidden;">';
            $rp_html .= '<div style="display:flex;align-items:center;">';
            $rp_html .= '<span style="font-weight:600;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60%;">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>';
            $rp_html .= '<small style="color:#777;margin-left:8px;flex:0 0 auto;white-space:nowrap;">#' . htmlspecialchars($bgid, ENT_QUOTES, 'UTF-8') . ' • ' . htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') . ' • ' . htmlspecialchars($fetched_at, ENT_QUOTES, 'UTF-8') . '</small>';
            if ($statusLabel !== '') {
                $rp_html .= '<span style="margin-left:8px;font-size:11px;padding:2px 8px;border-radius:10px;color:#fff;background:' . htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') . ';flex:0 0 auto;">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            $rp_html .= '</div>';
            // Second line: show imported/updated timestamps (null allowed) + attempts
            $metaLine = array();
            $metaLine[] = 'Attempts: ' . (int)$attempts;
            if ($importedCol) $metaLine[] = 'Imported: ' . ($imported_at ? htmlspecialchars(date('Y-m-d H:i', strtotime($imported_at)), ENT_QUOTES, 'UTF-8') : 'null');
            if ($updatedCol) $metaLine[] = 'Updated: ' . ($updated_at ? htmlspecialchars(date('Y-m-d H:i', strtotime($updated_at)), ENT_QUOTES, 'UTF-8') : 'null');
            $rp_html .= '<div style="color:#999;font-size:12px;white-space:nowrap;">' . implode(' • ', $metaLine) . '</div>';
            if ($meta !== '') {
                $rp_html .= '<div style="color:#999;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $rp_html .= '</div>';

            $rp_html .= '</div>'; // row
        }

        $rp_html .= '</div>'; // list
        return array($rp_html, (int)$recent_count, (int)$total_count);
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
        return DB_PREFIX . 'bg_fetched_products';
    }

    /**
     * Ensure bg_fetched_products exists (controller-side, no identifier escaping).
     */
    protected function ensureFetchedProductsTableExistsController() {
        $tbl = $this->getFetchedProductsTableName();
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

        // Best-effort: add missing columns (supports installs that manually created "updated at")
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM `" . $tbl . "`")->rows;
            $have = array();
            foreach ($cols as $c) $have[strtolower($c['Field'])] = true;
            if (!isset($have['imported_at'])) $this->db->query("ALTER TABLE `" . $tbl . "` ADD COLUMN `imported_at` datetime DEFAULT NULL");
            if (!isset($have['updated_at']) && !isset($have['updated at'])) $this->db->query("ALTER TABLE `" . $tbl . "` ADD COLUMN `updated_at` datetime DEFAULT NULL");
        } catch (\Throwable $e) {}
    }

    protected function getFetchedProductsUpdatedAtColumnNameController() {
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

    protected function getFetchedProductsImportedAtColumnNameController() {
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
     * Controller-side upsert into bg_fetched_products (guarantees table write happens here).
     * Returns number of rows attempted.
     */
    protected function saveFetchedProductsController(array $products, $status = 'pending') {
        if (empty($products)) return 0;
        $this->ensureFetchedProductsTableExistsController();
        $tbl = $this->getFetchedProductsTableName();
        $now = date('Y-m-d H:i:s');
        $count = 0;
        $status = $status ? (string)$status : 'pending';

        foreach ($products as $p) {
            if (!is_array($p) || empty($p['product_id'])) continue;
            $bgid = $this->db->escape((string)$p['product_id']);
            $cat = isset($p['cat_id']) ? $this->db->escape((string)$p['cat_id']) : '';
            $name = isset($p['product_name']) ? $this->db->escape((string)$p['product_name']) : (isset($p['name']) ? $this->db->escape((string)$p['name']) : '');
            $img = isset($p['img']) ? $this->db->escape((string)$p['img']) : '';
            $meta = isset($p['meta_desc']) ? $this->db->escape((string)$p['meta_desc']) : '';
            $rawJson = $this->db->escape(json_encode($p, JSON_UNESCAPED_UNICODE));

            $sql = "INSERT INTO `" . $tbl . "`
                (`bg_product_id`,`cat_id`,`name`,`img`,`meta_desc`,`raw_json`,`fetched_at`,`status`)
                VALUES ('" . $bgid . "','" . $cat . "','" . $name . "','" . $img . "','" . $meta . "','" . $rawJson . "','" . $this->db->escape($now) . "','" . $this->db->escape($status) . "')
                ON DUPLICATE KEY UPDATE
                  `cat_id` = VALUES(`cat_id`),
                  `name` = VALUES(`name`),
                  `img` = VALUES(`img`),
                  `meta_desc` = VALUES(`meta_desc`),
                  `raw_json` = VALUES(`raw_json`),
                  `fetched_at` = VALUES(`fetched_at`),
                  `status` = IF(`status` = 'imported', `status`, VALUES(`status`))";
            $this->db->query($sql);
            $count++;
        }

        return $count;
    }

    public function index() {
        $this->load->language('extension/module/banggood_import');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        // load model for categories and persisted products
        $this->load->model('extension/module/banggood_import');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            // Save module settings (including delete-missing)
            $this->model_setting_setting->editSetting('module_banggood_import', $this->request->post);

            // If the save was triggered via AJAX, return JSON success instead of redirecting
            $isAjax = !empty($this->request->server['HTTP_X_REQUESTED_WITH']) && strtolower($this->request->server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) {
                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode(array('success' => $this->language->get('text_success_settings'))));
                return;
            }

            $this->session->data['success'] = $this->language->get('text_success_settings');

            $this->response->redirect(
                $this->url->link(
                    'extension/module/banggood_import',
                    'user_token=' . $this->session->data['user_token'],
                    true
                )
            );
        }

        // Language strings and entries (include delete_missing)
        foreach ([
            'heading_title', 'text_edit', 'text_enabled', 'text_disabled', 'entry_status', 'entry_base_url', 'entry_app_id',
            'entry_app_secret', 'entry_api_key', 'entry_default_language', 'entry_default_currency', 'entry_category_id',
            'entry_max_products', 'entry_product_url', 'button_import_category', 'button_import_product_url',
            'entry_delete_missing', 'help_delete_missing',
            'entry_overwrite_option_images', 'help_overwrite_option_images'
        ] as $k) {
            $data[$k] = $this->language->get($k);
        }

        $data['button_save'] = $this->language->get('button_save') ?: 'Save';
        $data['button_cancel'] = $this->language->get('button_cancel') ?: 'Cancel';

        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
        $data['success'] = '';
        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        }

        // Breadcrumbs
        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link(
                'common/dashboard',
                'user_token=' . $this->session->data['user_token'],
                true
            )
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link(
                'marketplace/extension',
                'user_token=' . $this->session->data['user_token'] . '&type=module',
                true
            )
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link(
                'extension/module/banggood_import',
                'user_token=' . $this->session->data['user_token'],
                true
            )
        ];

        // Form actions
        $data['action'] = $this->url->link(
            'extension/module/banggood_import',
            'user_token=' . $this->session->data['user_token'],
            true
        );
        $data['cancel'] = $this->url->link(
            'marketplace/extension',
            'user_token=' . $this->session->data['user_token'] . '&type=module',
            true
        );

        // Settings fields (added delete_missing)
        $fields = [
            'module_banggood_import_status',
            'module_banggood_import_base_url',
            'module_banggood_import_app_id',
            'module_banggood_import_app_secret',
            'module_banggood_import_api_key',
            'module_banggood_import_default_language',
            'module_banggood_import_default_currency',
            'module_banggood_import_delete_missing', // NEW checkbox
            'module_banggood_import_overwrite_option_images' // overwrite POA option images
        ];
        foreach ($fields as $field) {
            $data[$field] = isset($this->request->post[$field])
                ? $this->request->post[$field]
                : $this->config->get($field);
        }
        if (empty($data['module_banggood_import_base_url'])) {
            $data['module_banggood_import_base_url'] = 'https://api.banggood.com';
        }
        if (empty($data['module_banggood_import_default_language'])) {
            $data['module_banggood_import_default_language'] = 'en';
        }
        if (empty($data['module_banggood_import_default_currency'])) {
            $data['module_banggood_import_default_currency'] = 'USD';
        }

        // Logging UI disabled by request.

        $data['import_category_url'] = $this->url->link(
            'extension/module/banggood_import/importCategory',
            'user_token=' . $this->session->data['user_token'], true
        );
        $data['import_product_url_action'] = $this->url->link(
            'extension/module/banggood_import/importProductUrl',
            'user_token=' . $this->session->data['user_token'], true
        );
        $data['user_token'] = $this->session->data['user_token'];

        // Pre-render categories for initial view (so admin sees current tree without clicking)
        try {
            // Fetch rows from bg tables (prefer bg_category, fallback to bg_category_import)
            $getRowsFromBgTable = function($shortTable) {
                $fullTable = DB_PREFIX . $shortTable;
                try {
                    $q = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($fullTable) . "'");
                    if (!$q->num_rows) return array();
                    $colsQr = $this->db->query("SHOW COLUMNS FROM `" . $this->db->escape($fullTable) . "`");
                    if (!$colsQr->num_rows) return array();
                    $available = array();
                    foreach ($colsQr->rows as $c) $available[] = $c['Field'];
                    // Determine id/parent/name columns
                    $idCandidates = ['cat_id','bg_cat_id','category_id','id','bgc_id'];
                    $parentCandidates = ['parent_id','parent_cat_id','parent','parentId'];
                    $nameCandidates = ['name','cat_name','category_name','title'];
                    $idCol = null; $parentCol = null; $nameCol = null;
                    foreach ($idCandidates as $c) if (in_array($c, $available)) { $idCol = $c; break; }
                    foreach ($parentCandidates as $c) if (in_array($c, $available)) { $parentCol = $c; break; }
                    foreach ($nameCandidates as $c) if (in_array($c, $available)) { $nameCol = $c; break; }
                    if (!$idCol || !$parentCol || !$nameCol) return array();
                    $sql = "SELECT `" . $this->db->escape($idCol) . "` AS cat_id, `" . $this->db->escape($parentCol) . "` AS parent_cat_id, `" . $this->db->escape($nameCol) . "` AS name
                            FROM `" . $this->db->escape($fullTable) . "`
                            ORDER BY `" . $this->db->escape($parentCol) . "`, `" . $this->db->escape($nameCol) . "`";
                    $qr = $this->db->query($sql);
                    return $qr->rows;
                } catch (\Throwable $e) {
                    return array();
                }
            };

            $rows = $getRowsFromBgTable('bg_category');
            if (!$rows) $rows = $getRowsFromBgTable('bg_category_import');

            // If no BG rows found, show friendly message (do not fallback to core categories)
            if (!$rows) {
                $data['bg_categories_html'] = '<div class="text-muted">No Banggood categories found. Click "Update Categories" to import from Banggood.</div>';
            } else {
                // Normalize rows and render tree
                $normalized = array();
                foreach ($rows as $r) {
                    // the helper returns fields as cat_id, parent_cat_id, name already (see SQL alias)
                    $cat_id = isset($r['cat_id']) ? (string)$r['cat_id'] : '';
                    if ($cat_id === '') continue;
                    $parent = isset($r['parent_cat_id']) ? (string)$r['parent_cat_id'] : '0';
                    $name = isset($r['name']) ? $r['name'] : '';
                    // keep the row in a consistent structure (use keys cat_id & parent_cat_id)
                    $normalized[] = array('cat_id' => $cat_id, 'parent_cat_id' => $parent, 'name' => $name);
                }

                // buildBgTree now understands both bgc-style rows and cat_id-style rows
                $tree_html = $this->buildBgTree($normalized);
                $data['bg_categories_html'] = $this->renderBgTreeHtml($tree_html);
            }
        } catch (Exception $e) {
            // If anything fails, fall back to the model helper if available, otherwise show an error.
            try {
                $data['bg_categories_html'] = $this->model_extension_module_banggood_import->getBgCategoriesHtml();
            } catch (Exception $e2) {
                $data['bg_categories_html'] = '<div class="text-danger">Error loading categories: ' . htmlspecialchars($e2->getMessage()) . '</div>';
            }
        }

        // --- Start: render persisted fetched products for initial view ---
        try {
            list($rp_html, $recent_count, $total_count) = $this->renderFetchedProductsList(200);
        } catch (\Throwable $e) {
            $rp_html = '<div class="text-danger">Could not load persisted products: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
            $recent_count = 0;
            $total_count = 0;
        }

        $data['bg_fetched_products_html']  = $rp_html;
        $data['bg_fetched_products_count'] = (int)$recent_count;
        $data['bg_fetched_products_total'] = (int)$total_count;
        // --- End: render persisted fetched products for initial view (compact one-line rows) ---

        // Expose update URL for JS (clean URL generation)
        $data['update_categories_url'] = $this->url->link(
            'extension/module/banggood_import/updateCategories',
            'user_token=' . $this->session->data['user_token'],
            true
        );

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput(
            $this->load->view('extension/module/banggood_import', $data)
        );
    }

    /**
     * buildBgTree
     *
     * Accepts an array of rows in multiple possible formats:
     * - bg-style rows with keys bgc_id, bg_cat_id, parent_id, name
     * - normalized rows with keys cat_id, parent_cat_id, name (string ids)
     *
     * Returns only the UL/LI tree markup. Control/search block is emitted by renderBgTreeHtml()
     */
    public function buildBgTree($rows = null) {
        $max_nodes = 30000;
        $max_depth = 100;

        if ($rows === null) {
            try {
                $q = $this->db->query("SELECT bgc_id, bg_cat_id, parent_id, name FROM `" . DB_PREFIX . "bg_category` ORDER BY parent_id ASC, name ASC");
                $rows = $q->rows;
            } catch (\Throwable $e) {
                return '<div class="text-muted">No categories available.</div>';
            }
        }

        if (!is_array($rows) || empty($rows)) {
            return '<div class="text-muted">No categories found.</div>';
        }

        // Determine which keys the rows provide (support both formats)
        $first = reset($rows);
        $has_bgc = isset($first['bgc_id']);
        $has_bgcat = isset($first['bg_cat_id']);
        $has_cat = isset($first['cat_id']);
        $has_parent_id = isset($first['parent_id']);
        $has_parent_cat = isset($first['parent_cat_id']);
        $name_key = isset($first['name']) ? 'name' : (isset($first['cat_name']) ? 'cat_name' : null);

        // Normalize into an internal rows array with these keys:
        // id_key (string), parent_key (string), bg_cat_id (string for display), name
        $internal = array();
        $index = 0;
        foreach ($rows as $r) {
            $index++;
            // determine id (use bgc_id if present, else cat_id or bg_cat_id or fallback to index)
            if ($has_bgc) {
                $id = (string)(isset($r['bgc_id']) ? $r['bgc_id'] : $index);
            } elseif ($has_cat) {
                $id = (string)$r['cat_id'];
            } elseif ($has_bgcat) {
                // use bg_cat_id (string) as id
                $id = (string)$r['bg_cat_id'];
            } else {
                $id = (string)$index;
            }

            // determine parent raw
            if ($has_parent_id) {
                $parent_raw = isset($r['parent_id']) ? (string)$r['parent_id'] : '0';
            } elseif ($has_parent_cat) {
                $parent_raw = isset($r['parent_cat_id']) ? (string)$r['parent_cat_id'] : '0';
            } else {
                $parent_raw = isset($r['parent']) ? (string)$r['parent'] : '0';
            }

            // display bg_cat_id (prefer bg_cat_id then cat_id)
            if (isset($r['bg_cat_id'])) $bgcat = (string)$r['bg_cat_id'];
            elseif (isset($r['cat_id'])) $bgcat = (string)$r['cat_id'];
            else $bgcat = $id;

            // name
            $name = '';
            if (isset($r['name'])) $name = $r['name'];
            elseif (isset($r['cat_name'])) $name = $r['cat_name'];
            elseif (isset($r['title'])) $name = $r['title'];

            $internal[] = array(
                'id' => $id,
                'parent' => ($parent_raw === '' || $parent_raw === null) ? '0' : (string)$parent_raw,
                'bg_cat_id' => $bgcat,
                'name' => (string)$name,
                // keep original row in case model code needs it later (not used for rendering)
                'orig' => $r
            );
        }

        // Build children map keyed by parent id string
        $children = array();
        foreach ($internal as $node) {
            $p = $node['parent'] ?: '0';
            $children[$p][] = $node;
        }

        // Roots are nodes whose parent is '0' or whose parent is missing
        $roots = isset($children['0']) ? $children['0'] : array();
        // Also include any nodes whose parent is not found in the id set as roots
        $all_ids = array();
        foreach ($internal as $n) $all_ids[$n['id']] = true;
        foreach ($internal as $n) {
            if ($n['parent'] !== '0' && !isset($all_ids[$n['parent']])) {
                $roots[] = $n;
            }
        }

        // Prepare inline JS for inline toggle (keeps working when inserted via innerHTML)
        // NOTE: this inline handler toggles expanded class and visibility. The delegated handler in renderBgTreeHtml also adjusts the visible arrow glyph;
        // both cooperate — we ensure consistent initial rendering from renderBgTreeHtml by collapsing and normalizing toggle glyphs there.
        $toggle_js = "var li=this.parentNode;var child=null;for(var i=0;i<li.childNodes.length;i++){var n=li.childNodes[i];if(n.nodeType===1 && n.tagName.toLowerCase()==='ul' && n.classList.contains('bg-tree')){child=n;break;}}if(child){if(child.style.display==='none'){child.style.display='';this.classList.add('expanded');this.setAttribute('aria-expanded','true');this.innerHTML='&#9662;';}else{child.style.display='none';this.classList.remove('expanded');this.setAttribute('aria-expanded','false');this.innerHTML='&#9656;';}}return false;";

        // Iterative rendering with stack
        $html = '';
        $nodes_rendered = 0;
        $stack = array();
        $stack[] = array('nodes' => $roots, 'index' => 0, 'depth' => 0, 'opened' => false, 'parent_id' => '0');

        while (!empty($stack)) {
            $frame_index = count($stack) - 1;
            $frame =& $stack[$frame_index];

            // Limits
            if ($frame['depth'] > $max_depth) {
                $html .= '<div class="text-muted">Category tree truncated: depth limit reached.</div>';
                break;
            }
            if ($nodes_rendered > $max_nodes) {
                $html .= '<div class="text-muted">Category tree truncated: node limit reached.</div>';
                break;
            }

            if (!$frame['opened']) {
                // Rendered ULs start collapsed (display:none) — renderBgTreeHtml's script will make root-level ULs visible.
                $html .= '<ul class="bg-tree" style="display:none">';
                $frame['opened'] = true;
            }

            if ($frame['index'] >= count($frame['nodes'])) {
                $html .= '</ul>';
                array_pop($stack);
                if (!empty($stack)) {
                    $html .= '</li>'; // close parent's li wrapper
                }
                continue;
            }

            $node = $frame['nodes'][$frame['index']];
            $frame['index']++;

            $node_id = (string)$node['id'];
            $bg_cat_id = htmlspecialchars($node['bg_cat_id'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8');

            // Determine children using id matching (parent values are raw ids matching either cat_id/bg_cat_id or bgc_id)
            $hasChildren = isset($children[$node_id]) && is_array($children[$node_id]) && count($children[$node_id]) > 0;

            // Render li with a class when it has children so JS can target it
            $html .= '<li class="bg-node' . ($hasChildren ? ' has-children' : '') . '" data-bgc-id="' . $node_id . '">';

            if ($hasChildren) {
                // NOTE: style added to button to remove native button chrome (square) while preserving semantics & behaviour.
                // We explicitly render the arrow as an HTML entity and include sizing inline to avoid a flash / sizing jitter
                // before the stylesheet is applied in the browser.
                $html .= '<button type="button" class="bg-toggle" aria-expanded="false" onclick="' . $toggle_js . '" style="appearance:none;-webkit-appearance:none;border:0;background:transparent;padding:0;margin-right:6px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;font-size:14px;box-shadow:none;border-radius:2px;background-image:none;">&#9656;</button> ';
                $html .= '<span class="bg-label">';
                $html .= '<span class="bg-name">' . $name . '</span>';
                $html .= '<span class="bg-id bg-id-box" data-bg-id="' . $bg_cat_id . '">#' . $bg_cat_id . '</span>';
                $html .= '</span>';

                $nodes_rendered++;

                // push children frame (children already contain full node structure)
                $stack[] = array(
                    'nodes' => $children[$node_id],
                    'index' => 0,
                    'depth' => $frame['depth'] + 1,
                    'opened' => false,
                    'parent_id' => $node_id
                );
            } else {
                // no children: placeholder toggle to keep alignment
                $html .= '<span class="bg-toggle" aria-hidden="true" style="visibility:hidden;display:inline-block;width:18px"></span> ';
                $html .= '<span class="bg-label">';
                $html .= '<span class="bg-name">' . $name . '</span>';
                $html .= '<span class="bg-id bg-id-box" data-bg-id="' . $bg_cat_id . '">#' . $bg_cat_id . '</span>';
                $html .= '</span>';
                $html .= '</li>';
                $nodes_rendered++;
            }
        }

        if (trim($html) === '') {
            return '<div class="text-muted">No categories found.</div>';
        }

        // buildBgTree intentionally does NOT emit control/search block or duplicate scripts.
        return $html;
    }

    /**
     * renderBgTreeHtml
     *
     * Wraps the tree HTML with a single control/search block + JS hookup.
     * Uses a scoped inline script that only operates inside #banggood-categories to avoid cross-talk.
     */
    public function renderBgTreeHtml($treeHtml = null) {
        try {
            if ($treeHtml === null) {
                if (method_exists($this, 'buildBgTree')) {
                    $treeHtml = $this->buildBgTree();
                } else {
                    $treeHtml = '<div class="text-muted">Category tree not available (buildBgTree missing).</div>';
                }
            }

            // This script ensures the tree is collapsed on initial render, normalizes toggle glyphs
            // and sets consistent sizes for toggle buttons so they don't appear large until user interacts.
            $html = <<<HTML
<div style="margin-bottom:8px">
  <input id="bg-tree-search" type="text" placeholder="Search categories..." style="width:60%;padding:6px;border:1px solid #ccc;border-radius:3px;margin-right:6px" />
  <button id="bg-tree-expand-all" type="button" style="padding:6px 8px;margin-right:4px" class="btn btn-default btn-sm">Expand all</button>
  <button id="bg-tree-collapse-all" type="button" style="padding:6px 8px" class="btn btn-default btn-sm">Collapse all</button>
</div>
{$treeHtml}
<script>
(function(){
  // Scope everything to the container so multiple instances won't conflict
  var container = document.getElementById('banggood-categories');
  if (!container) return;

  /* Inject scoped CSS to remove native list markers (::marker) for bg-tree inside the container
     and normalize toggle sizing so the button doesn't jump between two sizes. */
  var css = [
    '#banggood-categories ul.bg-tree,#banggood-categories ul.bg-tree ul{list-style:none!important;margin:0;padding-left:18px}',
    '#banggood-categories ul.bg-tree li::marker{content:\"\"!important;display:none!important}',
    '#banggood-categories ul.bg-tree li{list-style:none!important;list-style-image:none!important}',
    '#banggood-categories .bg-id-box{display:inline-block;background:#f5f5f5;border:1px solid #ddd;padding:2px 6px;border-radius:3px;margin-left:8px;color:#333;font-size:11px;}',
    '#banggood-categories .bg-toggle{font-size:14px; width:20px; height:20px; display:inline-flex; align-items:center; justify-content:center; padding:0; margin-right:6px; line-height:1; border-radius:2px; cursor:pointer; background:transparent; border:0;}',
    '#banggood-categories .bg-toggle[aria-hidden=\"true\"]{visibility:hidden}',
    '#banggood-categories .bg-toggle .bg-toggle-icon{font-size:12px; display:inline-block; transform:translateY(0);}',
    '#banggood-categories .bg-toggle.expanded{font-weight:600}'
  ].join('');
  var styleEl = document.createElement('style');
  styleEl.type = 'text/css';
  try { styleEl.appendChild(document.createTextNode(css)); } catch(e) { styleEl.styleSheet && (styleEl.styleSheet.cssText = css); }
  (document.head || document.getElementsByTagName('head')[0]).appendChild(styleEl);

  // Safe setAll that only touches elements inside the container
  function setAll(expand) {
    // Toggle only direct child subtree ULs under li.bg-node within the container
    Array.prototype.forEach.call(container.querySelectorAll('li.bg-node > ul.bg-tree'), function(u){
      u.style.display = expand ? '' : 'none';
    });

    // Update toggle buttons inside the container
    Array.prototype.forEach.call(container.querySelectorAll('.bg-toggle'), function(b){
      if (expand) {
        b.classList.add('expanded');
        b.setAttribute('aria-expanded','true');
        b.innerHTML = '&#9662;'; // down arrow
      } else {
        b.classList.remove('expanded');
        b.setAttribute('aria-expanded','false');
        b.innerHTML = '&#9656;'; // right arrow
      }
    });

    // Ensure root-level ULs inside the container (not nested in an LI) remain visible
    Array.prototype.forEach.call(container.querySelectorAll('ul.bg-tree'), function(u){
      var cur = u.parentNode;
      var isNested = false;
      while (cur && cur !== document && cur.nodeType === 1) {
        if (cur.nodeName.toLowerCase() === 'li') { isNested = true; break; }
        cur = cur.parentNode;
      }
      if (!isNested) u.style.display = '';
    });
  }

  // Wire Expand/Collapse buttons inside container (prefer scoped query)
  var btnExp = container.querySelector('#bg-tree-expand-all') || document.getElementById('bg-tree-expand-all');
  var btnCol = container.querySelector('#bg-tree-collapse-all') || document.getElementById('bg-tree-collapse-all');
  if (btnExp) btnExp.addEventListener('click', function(){ setAll(true); });
  if (btnCol) btnCol.addEventListener('click', function(){ setAll(false); });

  // Delegate toggle clicks (scoped to container) - updates glyphs consistently
  Array.prototype.forEach.call(container.querySelectorAll('.bg-tree'), function(root){
    root.addEventListener('click', function(e){
      var btn = e.target;
      while (btn && !btn.classList.contains('bg-toggle')) {
        btn = btn.parentNode;
        if (!btn || !btn.classList) return;
      }
      if (!btn) return;
      var li = btn.parentNode;
      var child = li.querySelector(':scope > ul.bg-tree');
      var expanded = btn.classList.contains('expanded');
      if (expanded) {
        btn.classList.remove('expanded');
        btn.setAttribute('aria-expanded','false');
        if (child) child.style.display = 'none';
        btn.innerHTML = '&#9656;';
      } else {
        btn.classList.add('expanded');
        btn.setAttribute('aria-expanded','true');
        if (child) child.style.display = '';
        btn.innerHTML = '&#9662;';
      }
      e.stopPropagation();
      e.preventDefault();
    }, true);
  });

  // Search scoped to container
  var input = container.querySelector('#bg-tree-search') || document.getElementById('bg-tree-search');
  if (input) {
    var timer = null;
    input.addEventListener('input', function(){
      clearTimeout(timer);
      var qv = input.value.trim().toLowerCase();
      timer = setTimeout(function(){
        if (qv === '') {
          container.querySelectorAll('.bg-node').forEach(function(n){ n.style.display = ''; });
          // collapse all direct child subtrees
          container.querySelectorAll('li.bg-node > ul.bg-tree').forEach(function(u){ u.style.display = 'none'; });
          container.querySelectorAll('.bg-toggle').forEach(function(b){ b.classList.remove('expanded'); b.setAttribute('aria-expanded','false'); b.innerHTML = '&#9656;'; });
          return;
        }
        container.querySelectorAll('.bg-node').forEach(function(n){ n.style.display = 'none'; });
        container.querySelectorAll('.bg-label').forEach(function(lbl){
          if (lbl.textContent.toLowerCase().indexOf(qv) !== -1) {
            var cur = lbl.parentNode;
            while (cur && cur.classList && cur.classList.contains('bg-node')) {
              cur.style.display = '';
              // ensure direct child UL (if present) is shown so you can see matches
              for (var i=0;i<cur.childNodes.length;i++){
                var cn = cur.childNodes[i];
                if (cn.nodeType===1 && cn.tagName.toLowerCase()==='ul' && cn.classList.contains('bg-tree')) cn.style.display = '';
              }
              // mark toggles expanded for visibility
              var t = cur.querySelector(':scope > .bg-toggle');
              if (t) { t.classList.add('expanded'); t.setAttribute('aria-expanded','true'); t.innerHTML = '&#9662;'; }
              var parent = cur.parentNode;
              while (parent && !parent.classList.contains('bg-node')) parent = parent.parentNode;
              cur = parent;
            }
          }
        });
      }, 160);
    });
  }

  // Make IDs clickable copy (scoped)
  Array.prototype.forEach.call(container.querySelectorAll('.bg-id'), function(b){
    b.style.cursor = 'pointer';
    b.addEventListener('click', function(e){
      var id = b.getAttribute('data-bg-id') || b.textContent.replace(/^#/, '');
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(id).catch(function(){});
      } else {
        var ta = document.createElement('textarea');
        ta.value = id;
        ta.style.position = 'fixed'; ta.style.left = '-9999px';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch(e){}
        document.body.removeChild(ta);
      }
      var orig = b.style.backgroundColor;
      b.style.transition = 'background-color 0.25s';
      b.style.backgroundColor = '#dff0d8';
      setTimeout(function(){ b.style.backgroundColor = orig; }, 600);
      e.stopPropagation();
    });
  });

  // Normalize toggles and collapse tree on initial render to avoid large glyphs until user interacts.
  // Doing this last ensures DOM is ready and CSS applied.
  try {
    setAll(false);
  } catch (e) {
    // swallow — non-fatal
  }
})();
</script>
HTML;
            return $html;
        } catch (\Throwable $e) {
            return '<div class="text-muted">Could not render category tree: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8") . '</div>';
        }
    }

    /**
     * importVariantsFromCombinations
     *
     * Keeps your existing logic intact (unchanged).
     */
    public function importVariantsFromCombinations($product_id, array $variants) {
        $language_id = (int)$this->config->get('config_language_id');

        $inserted = 0;
        $updated = 0;

        $normalizeKey = function($raw) {
            if ($raw === null) return '';
            $parts = preg_split('/[,\|]/', (string)$raw);
            $clean = array();
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
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
            $sku = isset($v['sku']) ? (string)$v['sku'] : '';
            $raw_key = isset($v['option_key']) ? $v['option_key'] : (isset($v['ov_ids']) ? (is_array($v['ov_ids']) ? implode(',', $v['ov_ids']) : $v['ov_ids']) : '');
            $canonical_key = $normalizeKey($raw_key);
            if ($canonical_key === '') {
                continue;
            }

            $bg_poa_ids = isset($v['bg_poa_ids']) ? $normalizePoa($v['bg_poa_ids']) : null;
            $bg_id = isset($v['bg_id']) && $v['bg_id'] ? (int)$v['bg_id'] : $extractBgIdFromSku($sku);
            $quantity = isset($v['quantity']) ? (int)$v['quantity'] : 0;
            $price = isset($v['price']) ? (float)$v['price'] : 0.0;
            $stock_status_token = isset($v['stock_status_token']) ? $v['stock_status_token'] : null;
            $stock_status_id = isset($v['stock_status_id']) ? (int)$v['stock_status_id'] : null;

            $option_text = isset($v['option_text']) ? (string)$v['option_text'] : '';
            $need_text = false;
            if ($option_text === '' || preg_match('/^[\d\|\-,\s]+$/', $option_text)) $need_text = true;
            if ($need_text) {
                $ov_ids = array();
                if (isset($v['ov_ids'])) {
                    if (is_array($v['ov_ids'])) $ov_ids = $v['ov_ids'];
                    else $ov_ids = preg_split('/[,\|]/', (string)$v['ov_ids']);
                } else {
                    $ov_ids = preg_split('/[,\|]/', (string)$raw_key);
                }
                $ov_ids = array_map('trim', $ov_ids);
                $ov_ids = array_values(array_filter($ov_ids, function($x){ return $x !== ''; }));
                $option_text = $buildOptionText($ov_ids);
            }

            $findSql = "SELECT variant_id FROM " . DB_PREFIX . "product_variant
                        WHERE product_id = '" . (int)$product_id . "'
                          AND (sku = '" . $this->db->escape($sku) . "' OR option_key = '" . $this->db->escape($canonical_key) . "')
                        LIMIT 1";
            $found = $this->db->query($findSql);

            if ($found->num_rows) {
                $variant_id = (int)$found->row['variant_id'];
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
        }

        return array('inserted' => $inserted, 'updated' => $updated);
    }

    public function importCategory() {
        $this->load->language('extension/module/banggood_import');
        $this->load->model('extension/module/banggood_import');

        $this->response->addHeader('Content-Type: application/json');
        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/module/banggood_import')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $category_id = isset($this->request->post['category_id']) ? trim($this->request->post['category_id']) : '';
            $max_products = isset($this->request->post['max_products']) ? (int)$this->request->post['max_products'] : 0;

            if ($category_id === '') {
                $json['error'] = $this->language->get('error_category_id');
            } else {
                try {
                    $result = $this->model_extension_module_banggood_import->importCategory($category_id, $max_products);
                    $json['success'] = sprintf(
                        $this->language->get('text_success_import_category'),
                        (int)$result['created'],
                        (int)$result['updated']
                    );
                } catch (Exception $e) {
                    $json['error'] = 'Import failed: ' . $e->getMessage();
                }
            }
        }
        $this->response->setOutput(json_encode($json));
    }

    public function importProductUrl() {
        $this->load->language('extension/module/banggood_import');
        $this->load->model('extension/module/banggood_import');

        $this->response->addHeader('Content-Type: application/json');
        $json = [];
        if (!$this->user->hasPermission('modify', 'extension/module/banggood_import')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $product_url = isset($this->request->post['product_url'])
                ? trim($this->request->post['product_url'])
                : '';

            if ($product_url === '') {
                $json['error'] = $this->language->get('error_product_url') . ' (received empty product_url)';
            } else {
                try {
                    $result = $this->model_extension_module_banggood_import->importProductUrl($product_url);

                    if (!empty($result['created'])) {
                        $json['success'] = $this->language->get('text_success_import_product_created');
                    } elseif (!empty($result['updated'])) {
                        $json['success'] = $this->language->get('text_success_import_product_updated');
                    } else {
                        $json['success'] = $this->language->get('text_success_import_product_nochange');
                    }
                } catch (Exception $e) {
                    $json['error'] = 'Import failed: ' . $e->getMessage();
                }
            }
        }

        $this->response->setOutput(json_encode($json));
    }

    /**
     * AJAX: getProductList
     *
     * Returns product list for a Banggood category using product/getProductList.
     * Expects POST: cat_id, page (optional)
     */
    public function getProductList() {
        $this->load->language('extension/module/banggood_import');
        $this->load->model('extension/module/banggood_import');

        $this->response->addHeader('Content-Type: application/json');
        $json = array();

        try {
            if (!$this->user->hasPermission('modify', 'extension/module/banggood_import')) {
                $json['error'] = $this->language->get('error_permission');
                $this->response->setOutput(json_encode($json));
                return;
            }

            $cat_id = isset($this->request->post['cat_id']) ? trim((string)$this->request->post['cat_id']) : '';
            $page = isset($this->request->post['page']) ? max(1, (int)$this->request->post['page']) : 1;
            $page_size = 20; // Banggood docs: 20 per page max
            $filters = array();
            foreach (array('add_date_start', 'add_date_end', 'modify_date_start', 'modify_date_end') as $k) {
                if (isset($this->request->post[$k]) && trim((string)$this->request->post[$k]) !== '') {
                    $filters[$k] = trim((string)$this->request->post[$k]);
                }
            }

            if ($cat_id === '') {
                $json['error'] = 'cat_id is required';
                $this->response->setOutput(json_encode($json));
                return;
            }

            $res = $this->model_extension_module_banggood_import->fetchProductList($cat_id, $page, $page_size, $filters);
            if (!$res || !empty($res['errors'])) {
                $json['error'] = !empty($res['errors']) ? implode("; ", $res['errors']) : 'Failed to fetch product list';
                $this->response->setOutput(json_encode($json));
                return;
            }

            $products = isset($res['products']) && is_array($res['products']) ? $res['products'] : array();

            // Render compact HTML list + pagination
            $html = '';
            if (empty($products)) {
                $html = '<div class="text-muted">No products found for this category.</div>';
            } else {
                foreach ($products as $p) {
                    $img = !empty($p['img']) ? $p['img'] : '';
                    $name = !empty($p['product_name']) ? $p['product_name'] : (isset($p['product_id']) ? $p['product_id'] : '');
                    $meta = !empty($p['meta_desc']) ? $p['meta_desc'] : '';
                    $id = isset($p['product_id']) ? (string)$p['product_id'] : '';

                    $html .= '<div class="row" style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #eee;">';
                    $html .= '<div class="col-xs-3"><img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:100%;height:auto" /></div>';
                    $html .= '<div class="col-xs-7"><strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>';
                    if ($meta !== '') {
                        $html .= '<div class="text-muted" style="font-size:12px;margin-top:4px">' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '</div>';
                    }
                    $html .= '</div>';
                    $html .= '<div class="col-xs-2 text-right">';
                    $html .= '<button class="btn btn-sm btn-primary bg-import-product" data-product-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" title="Import product"><i class="fa fa-download"></i></button>';
                    $html .= '</div></div>';
                }
            }

            $page_total = isset($res['page_total']) ? (int)$res['page_total'] : 0;
            $cur_page = isset($res['page']) ? (int)$res['page'] : $page;
            if ($page_total > 1) {
                $html .= '<div class="text-center" style="margin-top:12px">';
                $html .= '<ul class="pagination" style="margin:0;">';

                $start = max(1, $cur_page - 3);
                $end = min($page_total, $cur_page + 3);
                if ($start > 1) {
                    $html .= '<li><a href="#" class="bg-products-page" data-page="1">1</a></li>';
                    if ($start > 2) $html .= '<li class="disabled"><span>…</span></li>';
                }
                for ($i = $start; $i <= $end; $i++) {
                    if ($i === $cur_page) $html .= '<li class="active"><span>' . (int)$i . '</span></li>';
                    else $html .= '<li><a href="#" class="bg-products-page" data-page="' . (int)$i . '">' . (int)$i . '</a></li>';
                }
                if ($end < $page_total) {
                    if ($end < $page_total - 1) $html .= '<li class="disabled"><span>…</span></li>';
                    $html .= '<li><a href="#" class="bg-products-page" data-page="' . (int)$page_total . '">' . (int)$page_total . '</a></li>';
                }
                $html .= '</ul></div>';
            }

            $json['success'] = true;
            $json['products'] = $products;
            $json['page'] = $cur_page;
            $json['page_total'] = $page_total;
            $json['html'] = $html;
            $this->response->setOutput(json_encode($json));
        } catch (\Throwable $e) {
            $json['error'] = 'getProductList failed: ' . $e->getMessage();
            $this->response->setOutput(json_encode($json));
        }
    }

    /**
     * AJAX: getProductUpdateList
     *
     * Returns products updated within last N minutes using product/getProductUpdateList.
     * Expects POST: minutes (optional), page (optional)
     */
    public function getProductUpdateList() {
        $this->load->language('extension/module/banggood_import');
        $this->load->model('extension/module/banggood_import');

        $this->response->addHeader('Content-Type: application/json');
        $json = array();

        try {
            if (!$this->user->hasPermission('modify', 'extension/module/banggood_import')) {
                $json['error'] = $this->language->get('error_permission');
                $this->response->setOutput(json_encode($json));
                return;
            }

            $minutes = isset($this->request->post['minutes']) ? (int)$this->request->post['minutes'] : 30;
            $page = isset($this->request->post['page']) ? max(1, (int)$this->request->post['page']) : 1;
            $persist = !isset($this->request->post['persist']) || (int)$this->request->post['persist'] !== 0;

            $res = $this->model_extension_module_banggood_import->fetchProductUpdateList($minutes, $page);
            if (!$res || !empty($res['errors'])) {
                $json['error'] = !empty($res['errors']) ? implode("; ", $res['errors']) : 'Failed to fetch update list';
                $this->response->setOutput(json_encode($json));
                return;
            }

            $updates = isset($res['updates']) && is_array($res['updates']) ? $res['updates'] : array();
            $persisted = 0;

            // Persist update IDs into bg_fetched_products so cron can import them later
            if ($persist && !empty($updates)) {
                $toSave = array();
                foreach ($updates as $u) {
                    $id = isset($u['product_id']) ? (string)$u['product_id'] : '';
                    if ($id === '') continue;
                    $state = isset($u['state']) ? (int)$u['state'] : 0;
                    $modify = isset($u['modify_date']) ? (string)$u['modify_date'] : '';
                    $toSave[] = array(
                        'product_id' => $id,
                        'meta_desc' => 'UpdateList: ' . $modify . ' state=' . $state,
                        'modify_date' => $modify,
                        'state' => $state
                    );
                }
                try {
                    // Write as status=updated (shows red until updated_at is stamped by processing)
                    $persisted = (int)$this->saveFetchedProductsController($toSave, 'updated');
                } catch (\Throwable $e) {
                    // non-fatal
                    $persisted = 0;
                }
            }

            $html = '';
            if (empty($updates)) {
                $html = '<div class="text-muted">No updates found for this time window.</div>';
            } else {
                foreach ($updates as $u) {
                    $id = isset($u['product_id']) ? (string)$u['product_id'] : '';
                    $state = isset($u['state']) ? (int)$u['state'] : 0;
                    $modify = isset($u['modify_date']) ? (string)$u['modify_date'] : '';
                    $stateLabel = ($state === 2) ? 'halt' : (($state === 1) ? 'normal' : (string)$state);

                    $html .= '<div class="bg-compact-row" style="display:flex;align-items:center;padding:6px 8px;border-bottom:1px solid #f1f1f1;font-size:13px;white-space:nowrap;">';
                    $html .= '<div style="flex:1 1 auto;min-width:0;overflow:hidden;">';
                    $html .= '<div style="display:flex;align-items:center;">';
                    $html .= '<span style="font-weight:600;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60%;">#' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '</span>';
                    $html .= '<small style="color:#777;margin-left:8px;flex:0 0 auto;white-space:nowrap;">' . htmlspecialchars($modify, ENT_QUOTES, 'UTF-8') . ' • ' . htmlspecialchars($stateLabel, ENT_QUOTES, 'UTF-8') . '</small>';
                    $html .= '</div></div>';
                    $html .= '<div style="flex:0 0 46px;text-align:right;margin-left:8px;">';
                    $html .= '<button class="btn btn-sm btn-primary bg-import-product" data-product-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" title="Import product" style="padding:6px 8px;"><i class="fa fa-download"></i></button>';
                    $html .= '</div></div>';
                }
            }

            $json['success'] = true;
            $json['updates'] = $updates;
            $json['page'] = isset($res['page']) ? (int)$res['page'] : $page;
            $json['page_total'] = isset($res['page_total']) ? (int)$res['page_total'] : 0;
            $json['html'] = $html;
            $json['persisted'] = (int)$persisted;
            $this->response->setOutput(json_encode($json));
        } catch (\Throwable $e) {
            $json['error'] = 'getProductUpdateList failed: ' . $e->getMessage();
            $this->response->setOutput(json_encode($json));
        }
    }

    /**
     * AJAX: importProductById
     *
     * Imports a product by Banggood product_id (calls model importProductById()).
     * Expects POST: product_id
     */
    public function importProductById() {
        $this->load->language('extension/module/banggood_import');
        $this->load->model('extension/module/banggood_import');

        $this->response->addHeader('Content-Type: application/json');
        $json = array();

        try {
            if (!$this->user->hasPermission('modify', 'extension/module/banggood_import')) {
                $json['error'] = $this->language->get('error_permission');
                $this->response->setOutput(json_encode($json));
                return;
            }

            $product_id = isset($this->request->post['product_id']) ? trim((string)$this->request->post['product_id']) : '';
            if ($product_id === '') {
                $json['error'] = 'product_id is required';
                $this->response->setOutput(json_encode($json));
                return;
            }

            $res = $this->model_extension_module_banggood_import->importProductById($product_id);
            $result = isset($res['result']) ? (string)$res['result'] : 'ok';

            // If this product_id exists in the fetched-products queue, mark it as imported/updated.
            // This ensures manual imports (from "Fetch Updates" list) don't remain stuck as "pending".
            try {
                if (method_exists($this->model_extension_module_banggood_import, 'markFetchedProductImported')) {
                    $this->model_extension_module_banggood_import->markFetchedProductImported($product_id);
                }
            } catch (\Throwable $e) {
                // non-fatal
            }

            $json['success'] = $this->language->get('text_success_import_product_id') ?: 'Product imported successfully.';
            $json['result'] = $result;
            $this->response->setOutput(json_encode($json));
        } catch (\Throwable $e) {
            $json['error'] = 'Import failed: ' . $e->getMessage();
            $this->response->setOutput(json_encode($json));
        }
    }

    /**
     * AJAX endpoint used by admin UI to refresh and return a collapsible category tree.
     * Reads admin setting module_banggood_import_delete_missing and passes it to model import.
     */
    public function updateCategories() {
        $this->load->language('extension/module/banggood_import');
        $this->load->model('extension/module/banggood_import');

        $this->response->addHeader('Content-Type: application/json');
        $json = array();

        try {
            if (!$this->user->hasPermission('modify', 'extension/module/banggood_import')) {
                $json['error'] = $this->language->get('error_permission');
                $this->response->setOutput(json_encode($json));
                return;
            }

            // Accept a delete-missing toggle posted from UI (checkbox/hidden)
            $deleteMissing = isset($this->request->post['delete_missing']) ? (bool)$this->request->post['delete_missing'] : false;
            $force = true;

            // Ensure model loaded
            if (!isset($this->model_extension_module_banggood_import)) {
                $this->load->model('extension/module/banggood_import');
            }
            $model = isset($this->model_extension_module_banggood_import) ? $this->model_extension_module_banggood_import : null;

            // Attempt to call canonical model methods in order of preference.
            $importResult = null;
            try {
                if ($model && method_exists($model, 'importBgCategoriesToBgTables')) {
                    $importResult = $model->importBgCategoriesToBgTables($force, $deleteMissing);
                } elseif ($model && method_exists($model, 'refreshBgCategoriesFromApi')) {
                    $importResult = $model->refreshBgCategoriesFromApi();
                } else {
                    // Last-resort: include model file directly and instantiate
                    $model_file = DIR_APPLICATION . 'model/extension/module/banggood_import.php';
                    if (!is_file($model_file)) {
                        $alt = dirname(DIR_SYSTEM) . '/admin/model/extension/module/banggood_import.php';
                        if (is_file($alt)) $model_file = $alt;
                    }
                    if (is_file($model_file)) {
                        try {
                            require_once $model_file;
                            if (class_exists('ModelExtensionModuleBanggoodImport')) {
                                $inst = new ModelExtensionModuleBanggoodImport($this->registry);
                                if (method_exists($inst, 'importBgCategoriesToBgTables')) {
                                    $importResult = $inst->importBgCategoriesToBgTables($force, $deleteMissing);
                                    $model = $inst;
                                } elseif (method_exists($inst, 'refreshBgCategoriesFromApi')) {
                                    $importResult = $inst->refreshBgCategoriesFromApi();
                                    $model = $inst;
                                }
                            }
                        } catch (\Throwable $e) {
                            $importResult = array('inserted' => 0, 'updated' => 0, 'errors' => array('Model include/instantiate error: ' . $e->getMessage()), 'html' => '');
                        }
                    }
                }
            } catch (\Throwable $e) {
                $importResult = array('inserted' => 0, 'updated' => 0, 'errors' => array('Model exception: ' . $e->getMessage()), 'html' => '');
            }

            // Normalize legacy keys and ensure consistent shape
            if (!is_array($importResult)) {
                $importResult = array('inserted' => 0, 'updated' => 0, 'errors' => array('No import implementation found.'), 'html' => '');
            } else {
                if (isset($importResult['imported']) && !isset($importResult['inserted'])) {
                    $importResult['inserted'] = (int)$importResult['imported'];
                }
                $importResult['inserted'] = isset($importResult['inserted']) ? (int)$importResult['inserted'] : 0;
                $importResult['updated']  = isset($importResult['updated']) ? (int)$importResult['updated'] : 0;
                $importResult['errors']   = isset($importResult['errors']) && is_array($importResult['errors']) ? $importResult['errors'] : array();
                $importResult['html']     = isset($importResult['html']) ? $importResult['html'] : '';
            }

            // Write debug logs to storage so we can inspect web request results
            if (defined('DIR_STORAGE')) {
                $dbgFile = rtrim(DIR_STORAGE, '/\\') . DIRECTORY_SEPARATOR . 'banggood_import_web_debug.log';
                @file_put_contents($dbgFile, date('c') . " IMPORT_RESULT: " . json_encode($importResult, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);

                $dbgFile2 = rtrim(DIR_STORAGE, '/\\') . DIRECTORY_SEPARATOR . 'banggood_import_web_dbinfo.log';
                $dbinfo = array(
                    'DB_DRIVER'   => defined('DB_DRIVER') ? DB_DRIVER : null,
                    'DB_HOSTNAME' => defined('DB_HOSTNAME') ? DB_HOSTNAME : null,
                    'DB_DATABASE' => defined('DB_DATABASE') ? DB_DATABASE : null,
                    'DB_USERNAME' => defined('DB_USERNAME') ? DB_USERNAME : null,
                    'DB_PREFIX'   => defined('DB_PREFIX') ? DB_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : null)
                );
                @file_put_contents($dbgFile2, date('c') . " DB_INFO: " . json_encode($dbinfo, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
            }

            // Compose human readable message (keeps previous UI wording)
            $mirrorText = $deleteMissing ? ' (Mirror enabled: missing categories removed.)' : '';
            $json['success'] = "Categories updated: " . $importResult['inserted'] . " inserted, " . $importResult['updated'] . " updated." . $mirrorText;

            // include the normalized importResult and DB counts so front-end can show details
            $json['importResult'] = $importResult;

            // Always return refreshed category HTML built from DB, using Banggood cat_id for badges.
            try {
                $getRowsFromBgTable = function($shortTable) {
                    $fullTable = DB_PREFIX . $shortTable;
                    try {
                        $q = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($fullTable) . "'");
                        if (!$q->num_rows) return array();
                        $colsQr = $this->db->query("SHOW COLUMNS FROM `" . $this->db->escape($fullTable) . "`");
                        if (!$colsQr->num_rows) return array();
                        $available = array();
                        foreach ($colsQr->rows as $c) $available[] = $c['Field'];

                        // Determine id/parent/name columns
                        $idCandidates = ['cat_id','bg_cat_id','category_id','id','bgc_id'];
                        $parentCandidates = ['parent_id','parent_cat_id','parent','parentId'];
                        $nameCandidates = ['name','cat_name','category_name','title'];
                        $idCol = null; $parentCol = null; $nameCol = null;
                        foreach ($idCandidates as $c) if (in_array($c, $available)) { $idCol = $c; break; }
                        foreach ($parentCandidates as $c) if (in_array($c, $available)) { $parentCol = $c; break; }
                        foreach ($nameCandidates as $c) if (in_array($c, $available)) { $nameCol = $c; break; }
                        if (!$idCol || !$parentCol || !$nameCol) return array();

                        $sql = "SELECT `" . $this->db->escape($idCol) . "` AS cat_id, `" . $this->db->escape($parentCol) . "` AS parent_cat_id, `" . $this->db->escape($nameCol) . "` AS name
                                FROM `" . $this->db->escape($fullTable) . "`
                                ORDER BY `" . $this->db->escape($parentCol) . "`, `" . $this->db->escape($nameCol) . "`";
                        $qr = $this->db->query($sql);
                        return $qr->rows;
                    } catch (\Throwable $e) {
                        return array();
                    }
                };

                $rows = $getRowsFromBgTable('bg_category');
                if (!$rows) $rows = $getRowsFromBgTable('bg_category_import');

                if (!$rows) {
                    $json['html'] = '<div class="text-muted">No Banggood categories found. Click "Update Categories" to import from Banggood.</div>';
                } else {
                    $normalized = array();
                    foreach ($rows as $r) {
                        $cat_id = isset($r['cat_id']) ? (string)$r['cat_id'] : '';
                        if ($cat_id === '') continue;
                        $parent = isset($r['parent_cat_id']) ? (string)$r['parent_cat_id'] : '0';
                        $name = isset($r['name']) ? (string)$r['name'] : '';
                        $normalized[] = array('cat_id' => $cat_id, 'parent_cat_id' => $parent, 'name' => $name);
                    }
                    $treeHtml = $this->buildBgTree($normalized);
                    $json['html'] = $this->renderBgTreeHtml($treeHtml);
                }
            } catch (\Throwable $e) {
                // fallback to model if controller render fails
                try {
                    $json['html'] = $this->model_extension_module_banggood_import->getBgCategoriesHtml();
                } catch (\Throwable $e2) {
                    $json['html'] = '<div class="text-danger">Error rendering categories: ' . htmlspecialchars($e2->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
                }
            }

            // DB counts
            try {
                $q1 = $this->db->query("SELECT COUNT(*) AS cnt FROM `" . DB_PREFIX . "bg_category`");
                $json['db_counts']['bg_category'] = isset($q1->row['cnt']) ? (int)$q1->row['cnt'] : 0;

                $q2 = $this->db->query("SELECT COUNT(*) AS cnt FROM `" . DB_PREFIX . "bg_category_import`");
                $json['db_counts']['bg_category_import'] = isset($q2->row['cnt']) ? (int)$q2->row['cnt'] : 0;
            } catch (\Throwable $e) {
                $json['db_counts'] = array('bg_category' => 0, 'bg_category_import' => 0);
                $importResult['errors'][] = 'DB count error: ' . $e->getMessage();
            }

            // Surface API attempt flag if model set it
            if (isset($importResult['apiAttempted'])) {
                $json['apiAttempted'] = (bool)$importResult['apiAttempted'];
            } else {
                $json['apiAttempted'] = true; // assume true unless model states otherwise
            }

            // Surface any API-level errors in a top-level errors array for quick visibility
            if (!empty($importResult['errors'])) {
                $json['errors'] = $importResult['errors'];
            }

            $this->response->setOutput(json_encode($json));
            return;
        } catch (\Throwable $e) {
            $json['error'] = 'Controller exception: ' . $e->getMessage();
            $this->response->setOutput(json_encode($json));
            return;
        }
    }

    /**
     * Controller-level delegator: call model if available
     */
    public function importBgCategoriesToBgTables($force = false, $deleteMissing = false) {
        $inserted = 0;
        $updated = 0;
        $errors = array();

        // Ensure canonical target exists (creates a safe schema if missing)
        try {
            $targetTable = DB_PREFIX . 'bg_category';
            $this->ensureTargetTableExists($targetTable);
        } catch (\Throwable $e) {
            return array('inserted' => 0, 'updated' => 0, 'errors' => array('ensureTargetTableExists error: ' . $e->getMessage()), 'html' => '');
        }

        // Source: bg_category_import table
        $importTable = DB_PREFIX . 'bg_category_import';
        if (!$this->tableExists($importTable)) {
            // Nothing to import
            return array('inserted' => 0, 'updated' => 0, 'errors' => array('Import table not found: ' . $importTable), 'html' => $this->getBgCategoriesHtml());
        }

        // Read import rows in a streaming-friendly way (chunking)
        $limit = 1000;
        $offset = 0;
        $seenIds = array();

        try {
            while (true) {
                $qr = $this->db->query("SELECT id, bg_cat_id, raw_json, page FROM `" . $this->db->escape($importTable) . "` ORDER BY id ASC LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
                if (!$qr || empty($qr->rows)) break;

                foreach ($qr->rows as $row) {
                    $bg_cat_id = isset($row['bg_cat_id']) ? trim((string)$row['bg_cat_id']) : '';
                    $raw_json = isset($row['raw_json']) ? $row['raw_json'] : '';

                    // If bg_cat_id is empty try to extract from raw_json
                    if ($bg_cat_id === '') {
                        if (is_string($raw_json) && preg_match('/"cat_id"\s*:\s*"([^"]+)"/', $raw_json, $m)) {
                            $bg_cat_id = $m[1];
                        }
                    }

                    // Attempt to decode raw_json
                    $decoded = null;
                    if ($raw_json !== null && $raw_json !== '') {
                        $decoded = @json_decode($raw_json, true);
                        if (!is_array($decoded)) {
                            // try stripping slashes / try to find first JSON object substring
                            $try = stripslashes($raw_json);
                            $decoded = @json_decode($try, true);
                        }
                        if (!is_array($decoded) && is_string($raw_json) && preg_match('/(\{[\s\S]{10,}\})/U', $raw_json, $m2)) {
                            $decoded = @json_decode($m2[1], true);
                        }
                    }

                    // Build normalized values
                    $cat_id = '';
                    $name = '';
                    $parent = '0';

                    if (is_array($decoded)) {
                        // If top-level is cat_list, pick first or iterate (we only process each import row)
                        if (isset($decoded['cat_list']) && is_array($decoded['cat_list'])) {
                            // pick the first entry (this import row usually corresponds to a single category)
                            $first = reset($decoded['cat_list']);
                            if (is_array($first)) {
                                $cat_id = isset($first['cat_id']) ? (string)$first['cat_id'] : (isset($first['catId']) ? (string)$first['catId'] : $cat_id);
                                $name = isset($first['cat_name']) ? $first['cat_name'] : (isset($first['name']) ? $first['name'] : $name);
                                $parent = isset($first['parent_id']) ? (string)$first['parent_id'] : (isset($first['parent']) ? (string)$first['parent'] : $parent);
                            }
                        } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
                            $cand = $decoded['data'];
                            if (isset($cand['cat_list']) && is_array($cand['cat_list'])) $cand = reset($cand['cat_list']);
                            if (is_array($cand)) {
                                $cat_id = isset($cand['cat_id']) ? (string)$cand['cat_id'] : (isset($cand['catId']) ? (string)$cand['catId'] : $cat_id);
                                $name = isset($cand['cat_name']) ? $cand['cat_name'] : (isset($cand['name']) ? $cand['name'] : $name);
                                $parent = isset($cand['parent_id']) ? (string)$cand['parent_id'] : (isset($cand['parent']) ? (string)$cand['parent'] : $parent);
                            }
                        } else {
                            // treat decoded as a single category object or numeric list; prefer cat_id keys
                            if (isset($decoded['cat_id']) || isset($decoded['catId'])) {
                                $cat_id = isset($decoded['cat_id']) ? (string)$decoded['cat_id'] : (isset($decoded['catId']) ? (string)$decoded['catId'] : $cat_id);
                                $name   = isset($decoded['cat_name']) ? $decoded['cat_name'] : (isset($decoded['name']) ? $decoded['name'] : $name);
                                $parent = isset($decoded['parent_id']) ? (string)$decoded['parent_id'] : (isset($decoded['parent']) ? (string)$decoded['parent'] : $parent);
                            } else {
                                // If it's a list, try first element
                                $first = reset($decoded);
                                if (is_array($first) && (isset($first['cat_id']) || isset($first['catId']))) {
                                    $cat_id = isset($first['cat_id']) ? (string)$first['cat_id'] : (isset($first['catId']) ? (string)$first['catId'] : $cat_id);
                                    $name   = isset($first['cat_name']) ? $first['cat_name'] : (isset($first['name']) ? $first['name'] : $name);
                                    $parent = isset($first['parent_id']) ? (string)$first['parent_id'] : (isset($first['parent']) ? (string)$first['parent'] : $parent);
                                }
                            }
                        }
                    }

                    // Fallbacks: use explicit bg_cat_id field or simple regex fallback
                    if ($cat_id === '' && $bg_cat_id !== '') $cat_id = $bg_cat_id;
                    if ($cat_id === '' && is_string($raw_json) && preg_match('/"cat_id"\s*:\s*"([^"]+)"/', $raw_json, $m3)) {
                        $cat_id = $m3[1];
                    }
                    if ($name === '' && is_string($raw_json) && preg_match('/"cat_name"\s*:\s*"([^"]+)"/', $raw_json, $m4)) {
                        $name = $m4[1];
                    }
                    if ($parent === '0' && is_string($raw_json) && preg_match('/"parent_id"\s*:\s*"([^"]+)"/', $raw_json, $m5)) {
                        $parent = $m5[1];
                    }

                    // If still missing id skip
                    if ($cat_id === '') continue;

                    // Normalize strings
                    $cat_id = trim((string)$cat_id);
                    $name = trim((string)$name);
                    $parent = ($parent === null || $parent === '') ? '0' : trim((string)$parent);

                    // Track seen
                    $seenIds[] = $cat_id;

                    // Upsert into target bg_category (prefer bg_cat_id column if present)
                    try {
                        $cols = $this->getTableColumns($targetTable);
                        if (in_array('bg_cat_id', $cols)) {
                            // Check exists
                            $chk = $this->db->query("SELECT bg_cat_id FROM `" . $this->db->escape($targetTable) . "` WHERE bg_cat_id = '" . $this->db->escape($cat_id) . "' LIMIT 1");
                            if ($chk && $chk->num_rows) {
                                // Update name/parent if changed
                                $parts = array();
                                if (in_array('name', $cols)) $parts[] = "`name` = '" . $this->db->escape($name) . "'";
                                if (in_array('parent_id', $cols)) $parts[] = "`parent_id` = '" . $this->db->escape($parent) . "'";
                                if (!empty($parts)) {
                                    $this->db->query("UPDATE `" . $this->db->escape($targetTable) . "` SET " . implode(',', $parts) . " WHERE bg_cat_id = '" . $this->db->escape($cat_id) . "' LIMIT 1");
                                    $updated++;
                                }
                            } else {
                                $insCols = array(); $insVals = array();
                                if (in_array('bg_cat_id', $cols)) { $insCols[] = '`bg_cat_id`'; $insVals[] = "'" . $this->db->escape($cat_id) . "'"; }
                                if (in_array('name', $cols)) { $insCols[] = '`name`'; $insVals[] = "'" . $this->db->escape($name) . "'"; }
                                if (in_array('parent_id', $cols)) { $insCols[] = '`parent_id`'; $insVals[] = "'" . $this->db->escape($parent) . "'"; }
                                if (!empty($insCols)) {
                                    $this->db->query("INSERT INTO `" . $this->db->escape($targetTable) . "` (" . implode(',', $insCols) . ") VALUES (" . implode(',', $insVals) . ")");
                                    $inserted++;
                                }
                            }
                        } elseif (in_array('cat_id', $cols)) {
                            $chk = $this->db->query("SELECT cat_id FROM `" . $this->db->escape($targetTable) . "` WHERE cat_id = '" . $this->db->escape($cat_id) . "' LIMIT 1");
                            if ($chk && $chk->num_rows) {
                                $this->db->query("UPDATE `" . $this->db->escape($targetTable) . "` SET `parent_cat_id` = '" . $this->db->escape($parent) . "', `name` = '" . $this->db->escape($name) . "' WHERE cat_id = '" . $this->db->escape($cat_id) . "' LIMIT 1");
                                $updated++;
                            } else {
                                $this->db->query("INSERT INTO `" . $this->db->escape($targetTable) . "` (`cat_id`,`parent_cat_id`,`name`) VALUES ('" . $this->db->escape($cat_id) . "','" . $this->db->escape($parent) . "','" . $this->db->escape($name) . "')");
                                $inserted++;
                            }
                        } else {
                            // table doesn't have recognizable schema, attempt to create basic schema then insert
                            $this->ensureTargetTableExists($targetTable);
                            $cols = $this->getTableColumns($targetTable);
                            if (in_array('cat_id', $cols)) {
                                $this->db->query("INSERT INTO `" . $this->db->escape($targetTable) . "` (`cat_id`,`parent_cat_id`,`name`) VALUES ('" . $this->db->escape($cat_id) . "','" . $this->db->escape($parent) . "','" . $this->db->escape($name) . "')");
                                $inserted++;
                            } else {
                                // give up for this row
                                $errors[] = "Unrecognized bg_category target schema; cannot insert row for id {$cat_id}";
                            }
                        }
                    } catch (\Throwable $e) {
                        $errors[] = "DB error for id {$cat_id}: " . $e->getMessage();
                    }
                } // end foreach rows

                $offset += $limit;
                // yield to avoid infinite loop if something odd
                if ($offset > 1000000) break;
            } // end while
        } catch (\Throwable $e) {
            return array('inserted' => $inserted, 'updated' => $updated, 'errors' => array('Import query error: ' . $e->getMessage()), 'html' => '');
        }

        // Optional: delete missing rows from target if requested and we collected seen ids
        if ($deleteMissing && !empty($seenIds)) {
            try {
                $seenIds = array_values(array_unique($seenIds));
                $tmp = DB_PREFIX . 'bg_tmp_import_ids';
                $this->db->query("DROP TABLE IF EXISTS `" . $this->db->escape($tmp) . "`");
                $this->db->query("CREATE TABLE `" . $this->db->escape($tmp) . "` (`id` varchar(64) NOT NULL, INDEX(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $chunks = array_chunk($seenIds, 1000);
                foreach ($chunks as $chunk) {
                    $vals = array();
                    foreach ($chunk as $v) $vals[] = "('" . $this->db->escape((string)$v) . "')";
                    if (!empty($vals)) $this->db->query("INSERT INTO `" . $this->db->escape($tmp) . "` (`id`) VALUES " . implode(',', $vals));
                }
                // determine id column on target
                $cols = $this->getTableColumns($targetTable);
                $idCol = in_array('bg_cat_id', $cols) ? 'bg_cat_id' : (in_array('cat_id', $cols) ? 'cat_id' : null);
                if ($idCol) {
                    $this->db->query("DELETE t FROM `" . $this->db->escape($targetTable) . "` t LEFT JOIN `" . $this->db->escape($tmp) . "` k ON k.`id` = t.`" . $this->db->escape($idCol) . "` WHERE k.`id` IS NULL");
                }
                $this->db->query("DROP TABLE IF EXISTS `" . $this->db->escape($tmp) . "`");
            } catch (\Throwable $e) {
                $errors[] = 'Post-import sweep error: ' . $e->getMessage();
            }
        }

        // Compose HTML via helper
        try {
            $html = method_exists($this, 'getBgCategoriesHtml') ? $this->getBgCategoriesHtml() : '';
        } catch (\Throwable $e) {
            $html = '<div class="text-muted">No categories found.</div>';
            $errors[] = 'getBgCategoriesHtml error: ' . $e->getMessage();
        }

        return array(
            'inserted' => (int)$inserted,
            'updated'  => (int)$updated,
            'errors'   => $errors,
            'html'     => $html,
            'swept'    => ($deleteMissing ? true : false)
        );
    }

    /**
     * Fetch next chunk of products for persistence.
     *
     * New behavior (preferred):
     * - If POST contains cat_id, fetches product/getProductList for that category,
     *   takes the next N items (default 10) from the returned list using a (page, offset) cursor,
     *   and persists them into bg_fetched_products (DB_PREFIX + bg_fetched_products).
     *
     * Legacy behavior:
     * - If no cat_id is provided, iterates across categories (older implementation).
     */
    public function fetchProductsChunk() {
        $this->load->language('extension/module/banggood_import');
        $this->load->model('extension/module/banggood_import');
        $this->load->model('setting/setting');

        $this->response->addHeader('Content-Type: application/json');
        $json = array();

        try {
            if (!$this->user->hasPermission('modify', 'extension/module/banggood_import')) {
                $json['error'] = $this->language->get('error_permission');
                $this->response->setOutput(json_encode($json));
                return;
            }

            $chunk_size = isset($this->request->post['chunk_size']) ? max(1, (int)$this->request->post['chunk_size']) : 10;

            // Server-side cursor: stored in settings so it resumes after reloads.
            $cursorRaw = $this->config->get('module_banggood_import_fetch_cursor');
            $cursor = array('category_index' => 0, 'page' => 1, 'offset' => 0);
            if (is_string($cursorRaw) && $cursorRaw !== '') {
                $decoded = @json_decode($cursorRaw, true);
                if (is_array($decoded)) {
                    $cursor['category_index'] = isset($decoded['category_index']) ? max(0, (int)$decoded['category_index']) : 0;
                    $cursor['page'] = isset($decoded['page']) ? max(1, (int)$decoded['page']) : 1;
                    $cursor['offset'] = isset($decoded['offset']) ? max(0, (int)$decoded['offset']) : 0;
                }
            }

            $category_index = $cursor['category_index'];
            $page = $cursor['page'];
            $offset = $cursor['offset'];

            $fetchRows = function($shortTable) {
                $fullTable = DB_PREFIX . $shortTable;
                try {
                    $q = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($fullTable) . "'");
                    if (!$q->num_rows) return array();
                    $cols = $this->db->query("SHOW COLUMNS FROM `" . $this->db->escape($fullTable) . "`");
                    if (!$cols->num_rows) return array();
                    $available = array();
                    foreach ($cols->rows as $c) $available[] = $c['Field'];
                    $idCandidates = ['bg_cat_id','cat_id','category_id','id','bgc_id'];
                    $idCol = null;
                    foreach ($idCandidates as $c) if (in_array($c, $available)) { $idCol = $c; break; }
                    if (!$idCol) return array();
                    $qr = $this->db->query("SELECT `" . $this->db->escape($idCol) . "` AS cat_id FROM `" . $this->db->escape($fullTable) . "` ORDER BY `" . $this->db->escape($idCol) . "`");
                    return $qr->rows;
                } catch (\Throwable $e) {
                    return array();
                }
            };

            $rows = $fetchRows('bg_category');
            if (!$rows) $rows = $fetchRows('bg_category_import');
            if (empty($rows)) {
                $json['error'] = 'No Banggood categories available to iterate.';
                $this->response->setOutput(json_encode($json));
                return;
            }

            $total_categories = count($rows);
            $collected = array();
            $next_category_index = $category_index;
            $next_page = $page;
            $next_offset = $offset;
            $finished = false;
            $persisted = 0;
            $imported = 0;
            $import_errors = 0;

            // Walk the global product list across categories, resuming from cursor.
            for ($ci = $category_index; $ci < $total_categories && count($collected) < $chunk_size; $ci++) {
                $cat_id = isset($rows[$ci]['cat_id']) ? (string)$rows[$ci]['cat_id'] : '';
                if ($cat_id === '') continue;

                // Banggood docs: 20 products max per page.
                $api_page_size = 20;

                $currentPage = ($ci === $category_index) ? $page : 1;
                $currentOffset = ($ci === $category_index) ? $offset : 0;

                while (count($collected) < $chunk_size) {
                    $res = $this->model_extension_module_banggood_import->fetchProductList($cat_id, $currentPage, $api_page_size);
                    if (!$res || !empty($res['errors'])) {
                        break;
                    }

                    $products = !empty($res['products']) && is_array($res['products']) ? $res['products'] : array();
                    if (empty($products)) {
                        break;
                    }

                    $remaining = $chunk_size - count($collected);
                    $slice = array_slice($products, $currentOffset, $remaining);
                    foreach ($slice as $p) {
                        $collected[] = $p;
                        if (count($collected) >= $chunk_size) break;
                    }

                    $page_total = isset($res['page_total']) ? (int)$res['page_total'] : 0;
                    $currentOffset = $currentOffset + count($slice);
                    if ($currentOffset >= count($products)) {
                        $currentPage++;
                        $currentOffset = 0;
                    }

                    if ($page_total > 0 && $currentPage > $page_total) {
                        break;
                    }
                }

                if (count($collected) >= $chunk_size) {
                    $next_category_index = $ci;
                    $next_page = $currentPage;
                    $next_offset = $currentOffset;

                    // If we moved past the last page for this category, advance category.
                    if (isset($page_total) && $page_total > 0 && $next_page > $page_total) {
                        $next_category_index = $ci + 1;
                        $next_page = 1;
                        $next_offset = 0;
                    }
                    break;
                } else {
                    // exhausted this category, move on to next
                    $next_category_index = $ci + 1;
                    $next_page = 1;
                    $next_offset = 0;
                }
            }

            if ($next_category_index >= $total_categories) {
                $finished = true;
            }

            // Persist fetched products into bg_fetched_products (controller-side, guaranteed).
            try {
                $persisted = (int)$this->saveFetchedProductsController($collected);
            } catch (\Throwable $e) {
                $json['error'] = 'Failed writing to fetched-products table: ' . $e->getMessage();
                $this->response->setOutput(json_encode($json));
                return;
            }

            // Verify inserts hit the expected table name (helps diagnose prefix/escaping issues)
            $persist_tbl = $this->getFetchedProductsTableName();
            $persist_expected = 0;
            $persist_found = 0;
            $persist_total = null;
            $persist_db = null;
            try {
                // Which DB are we actually connected to?
                $dbRow = $this->db->query("SELECT DATABASE() AS db")->row;
                $persist_db = isset($dbRow['db']) ? (string)$dbRow['db'] : null;

                $qt = $this->db->query("SELECT COUNT(*) AS cnt FROM `" . $persist_tbl . "`");
                $persist_total = isset($qt->row['cnt']) ? (int)$qt->row['cnt'] : null;

                if (!empty($collected)) {
                    $ids = array();
                    foreach ($collected as $p) {
                        if (!empty($p['product_id'])) $ids[] = "'" . $this->db->escape((string)$p['product_id']) . "'";
                    }
                    $ids = array_values(array_unique($ids));
                    $persist_expected = count($ids);
                    if ($persist_expected > 0) {
                        $qv = $this->db->query("SELECT COUNT(*) AS cnt FROM `" . $persist_tbl . "` WHERE `bg_product_id` IN (" . implode(',', $ids) . ")");
                        $persist_found = isset($qv->row['cnt']) ? (int)$qv->row['cnt'] : 0;
                    }
                }
            } catch (\Throwable $e) {
                // non-fatal
                $persist_found = 0;
            }

            // If we can't verify the rows exist in the expected table, stop here (avoid importing without queue record).
            if ($persist_expected > 0 && $persist_found < $persist_expected) {
                $json['error'] = 'Fetch queue write verification failed: expected ' . (int)$persist_expected . ' rows in ' . $persist_tbl . ' but found ' . (int)$persist_found . '. Not importing to avoid mismatch.';
                $this->response->setOutput(json_encode($json));
                return;
            }

            foreach ($collected as $p) {
                $pid = isset($p['product_id']) ? (string)$p['product_id'] : '';
                if ($pid === '') continue;
                try {
                    $this->model_extension_module_banggood_import->importProductById($pid);
                    if (method_exists($this->model_extension_module_banggood_import, 'markFetchedProductImported')) {
                        $this->model_extension_module_banggood_import->markFetchedProductImported($pid);
                    }
                    $imported++;
                } catch (\Throwable $e) {
                    if (method_exists($this->model_extension_module_banggood_import, 'markFetchedProductError')) {
                        $this->model_extension_module_banggood_import->markFetchedProductError($pid, $e->getMessage());
                    }
                    $import_errors++;
                }
            }

            // Save updated cursor back to settings so the next click continues where we left off.
            $cursorNew = json_encode(array('category_index' => (int)$next_category_index, 'page' => (int)$next_page, 'offset' => (int)$next_offset));
            try {
                if (method_exists($this->model_setting_setting, 'editSettingValue')) {
                    $this->model_setting_setting->editSettingValue('module_banggood_import', 'module_banggood_import_fetch_cursor', $cursorNew);
                } else {
                    // fallback: merge into current module settings and write them back
                    $cur = $this->model_setting_setting->getSetting('module_banggood_import');
                    if (!is_array($cur)) $cur = array();
                    $cur['module_banggood_import_fetch_cursor'] = $cursorNew;
                    $this->model_setting_setting->editSetting('module_banggood_import', $cur);
                }
            } catch (\Throwable $e) {
                // non-fatal
            }

            // IMPORTANT: return the list strictly from bg_fetched_products so the UI matches DB.
            list($html, $recent_count, $total_count) = $this->renderFetchedProductsList(200);

            $json['success'] = true;
            $json['products'] = $collected;
            $json['html'] = $html;
            $json['imported'] = (int)$imported;
            $json['import_errors'] = (int)$import_errors;
            $json['next_position'] = array('category_index' => (int)$next_category_index, 'page' => (int)$next_page, 'offset' => (int)$next_offset);
            $json['finished'] = (bool)$finished;
        } catch (\Exception $e) {
            $json = array('error' => 'fetchProductsChunk failed: ' . $e->getMessage());
        }

        $this->response->setOutput(json_encode($json));
    }

    /**
     * AJAX: getFetchedProductsPanel
     *
     * Returns the HTML for the "Products" panel from the persisted fetch queue table,
     * so the admin UI can auto-refresh while imports are running.
     */
    public function getFetchedProductsPanel() {
        $this->load->language('extension/module/banggood_import');
        $this->load->model('extension/module/banggood_import');

        $this->response->addHeader('Content-Type: application/json');
        $json = array();

        try {
            if (!$this->user->hasPermission('modify', 'extension/module/banggood_import')) {
                $json['error'] = $this->language->get('error_permission');
                $this->response->setOutput(json_encode($json));
                return;
            }

            list($html, $recent_count, $total_count) = $this->renderFetchedProductsList(200);
            $json['success'] = true;
            $json['html'] = $html;
            $json['recent_count'] = (int)$recent_count;
            $json['total_count'] = (int)$total_count;
        } catch (\Throwable $e) {
            $json['error'] = 'getFetchedProductsPanel failed: ' . $e->getMessage();
        }

        $this->response->setOutput(json_encode($json));
    }

    /**
     * AJAX: resetFetchCursor
     *
     * Resets the server-side cursor used by fetchProductsChunk() so "Fetch" starts from the beginning again.
     * This only resets position; it does NOT clear bg_fetched_products.
     */
    public function resetFetchCursor() {
        $this->load->language('extension/module/banggood_import');
        $this->load->model('setting/setting');

        $this->response->addHeader('Content-Type: application/json');
        $json = array();

        try {
            if (!$this->user->hasPermission('modify', 'extension/module/banggood_import')) {
                $json['error'] = $this->language->get('error_permission');
                $this->response->setOutput(json_encode($json));
                return;
            }

            $cursorNew = json_encode(array('category_index' => 0, 'page' => 1, 'offset' => 0));
            try {
                if (method_exists($this->model_setting_setting, 'editSettingValue')) {
                    $this->model_setting_setting->editSettingValue('module_banggood_import', 'module_banggood_import_fetch_cursor', $cursorNew);
                } else {
                    $cur = $this->model_setting_setting->getSetting('module_banggood_import');
                    if (!is_array($cur)) $cur = array();
                    $cur['module_banggood_import_fetch_cursor'] = $cursorNew;
                    $this->model_setting_setting->editSetting('module_banggood_import', $cur);
                }
            } catch (\Throwable $e) {
                // non-fatal; still return success if we can read it back
            }

            $json['success'] = 'Fetch cursor reset. Next Fetch will start from the beginning.';
            $json['cursor'] = array('category_index' => 0, 'page' => 1, 'offset' => 0);
        } catch (\Throwable $e) {
            $json['error'] = 'resetFetchCursor failed: ' . $e->getMessage();
        }

        $this->response->setOutput(json_encode($json));
    }

    /**
     * Process pending persisted fetched products (for cron)
     */
    public function processFetchedProducts() {
        $this->load->language('extension/module/banggood_import');
        $this->load->model('extension/module/banggood_import');

        $this->response->addHeader('Content-Type: application/json');

        try {
            $secret = isset($this->request->get['s']) ? $this->request->get['s'] : '';
            $configured_secret = $this->config->get('module_banggood_import_cron_secret');
            if ($configured_secret && $secret !== $configured_secret) {
                http_response_code(403);
                $this->response->setOutput(json_encode(array('error' => 'Forbidden')));
                return;
            }

            $limit = isset($this->request->get['limit']) ? (int)$this->request->get['limit'] : 10;

            if (!method_exists($this->model_extension_module_banggood_import, 'fetchPendingForProcessing')) {
                $this->response->setOutput(json_encode(array('error' => 'Model method fetchPendingForProcessing missing')));
                return;
            }

            $rows = $this->model_extension_module_banggood_import->fetchPendingForProcessing($limit);
            $results = array('processed' => 0, 'success' => 0, 'errors' => 0);

            foreach ($rows as $row) {
                $bgid = isset($row['bg_product_id']) ? $row['bg_product_id'] : '';
                if (!$bgid) continue;
                try {
                    if (!method_exists($this->model_extension_module_banggood_import, 'importProductById')) {
                        $this->model_extension_module_banggood_import->importProductUrl($bgid);
                    } else {
                        $this->model_extension_module_banggood_import->importProductById($bgid);
                    }
                    if (method_exists($this->model_extension_module_banggood_import, 'markFetchedProductImported')) {
                        $this->model_extension_module_banggood_import->markFetchedProductImported($bgid);
                    }
                    $results['success']++;
                } catch (Exception $e) {
                    if (method_exists($this->model_extension_module_banggood_import, 'markFetchedProductError')) {
                        $this->model_extension_module_banggood_import->markFetchedProductError($bgid, $e->getMessage());
                    }
                    $results['errors']++;
                }
                $results['processed']++;
            }

            $this->response->setOutput(json_encode($results));
        } catch (\Exception $e) {
            $this->response->setOutput(json_encode(array('error' => 'processFetchedProducts failed: ' . $e->getMessage())));
        }
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/module/banggood_import')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }
}
