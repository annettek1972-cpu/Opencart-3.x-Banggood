<?php
class ModelCatalogProductVariant extends Model {
    /**
     * Insert or update a variant row for a product.
     *
     * $bg_poa_ids can be a comma-separated string or array.
     * Returns variant_id on success.
     */
    public function addOrUpdateVariant($product_id, $option_key, $option_text = '', $sku = null, $qty = 0, $price = null, $stock_status_id = null, $bg_poa_ids = null, $bg_id = null) {
        $product_id = (int)$product_id;
        $option_key = (string)$option_key;
        if ($product_id <= 0 || $option_key === '') return 0;

        // normalize bg_poa_ids to comma list
        if (is_array($bg_poa_ids)) $bg_poa_ids = implode(',', $bg_poa_ids);
        $bg_poa_ids = $bg_poa_ids !== null ? (string)$bg_poa_ids : null;

        // Check existing
        $q = $this->db->query("SELECT variant_id FROM `" . DB_PREFIX . "product_variant` WHERE product_id = " . $product_id . " AND option_key = '" . $this->db->escape($option_key) . "' LIMIT 1");
        if ($q->num_rows) {
            $variant_id = (int)$q->row['variant_id'];
            $parts = array();
            if ($sku !== null) $parts[] = "`sku` = '" . $this->db->escape($sku) . "'";
            if ($option_text !== '') $parts[] = "`option_text` = '" . $this->db->escape($option_text) . "'";
            $parts[] = "`quantity` = " . (int)$qty;
            if ($price !== null) $parts[] = "`price` = '" . (float)$price . "'";
            if ($stock_status_id !== null) $parts[] = "`stock_status_id` = " . (int)$stock_status_id;
            if ($bg_poa_ids !== null) $parts[] = "`bg_poa_ids` = '" . $this->db->escape($bg_poa_ids) . "'";
            if ($bg_id !== null) $parts[] = "`bg_id` = '" . $this->db->escape((string)$bg_id) . "'";
            $parts[] = "`date_modified` = NOW()";
            if (!empty($parts)) {
                $this->db->query("UPDATE `" . DB_PREFIX . "product_variant` SET " . implode(', ', $parts) . " WHERE variant_id = " . $variant_id);
            }
            return $variant_id;
        } else {
            $sql = "INSERT INTO `" . DB_PREFIX . "product_variant` (product_id, sku, option_key, option_text, quantity, price, stock_status_id, bg_poa_ids, bg_id, date_modified) VALUES (" .
                $product_id . ", " .
                ($sku !== null ? ("'" . $this->db->escape($sku) . "'") : "NULL") . ", " .
                "'" . $this->db->escape($option_key) . "', " .
                ($option_text !== '' ? ("'" . $this->db->escape($option_text) . "'") : "NULL") . ", " .
                (int)$qty . ", " .
                ($price !== null ? ("'" . (float)$price . "'") : "NULL") . ", " .
                ($stock_status_id !== null ? (int)$stock_status_id : "NULL") . ", " .
                ($bg_poa_ids !== null ? ("'" . $this->db->escape($bg_poa_ids) . "'") : "NULL") . ", " .
                ($bg_id !== null ? ("'" . $this->db->escape((string)$bg_id) . "'") : "NULL") . ", NOW())";
            $this->db->query($sql);
            return $this->db->getLastId();
        }
    }

    /**
     * Get a variant by product_id + option_key
     */
    public function getVariantByOptionKey($product_id, $option_key) {
        $product_id = (int)$product_id;
        $option_key = (string)$option_key;
        if ($product_id <= 0 || $option_key === '') return array();

        $q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_variant` WHERE product_id = " . $product_id . " AND option_key = '" . $this->db->escape($option_key) . "' LIMIT 1");
        if ($q->num_rows) return $q->row;
        return array();
    }

    /**
     * Get variant by variant_id
     */
    public function getVariant($variant_id) {
        $variant_id = (int)$variant_id;
        if ($variant_id <= 0) return array();
        $q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_variant` WHERE variant_id = " . $variant_id . " LIMIT 1");
        if ($q->num_rows) return $q->row;
        return array();
    }

    /**
     * Get all variants for a product (optionally include only those with qty>0)
     */
    public function getVariantsForProduct($product_id, $only_instock = false) {
        $product_id = (int)$product_id;
        if ($product_id <= 0) return array();
        $sql = "SELECT * FROM `" . DB_PREFIX . "product_variant` WHERE product_id = " . $product_id;
        if ($only_instock) $sql .= " AND quantity > 0";
        $sql .= " ORDER BY variant_id ASC";
        $q = $this->db->query($sql);
        return $q->rows;
    }

    /**
     * Decrement variant quantity by $qty (positive int). Returns new quantity or false on error.
     */
    public function decrementVariantQuantity($variant_id, $qty = 1) {
        $variant_id = (int)$variant_id;
        $qty = (int)$qty;
        if ($variant_id <= 0 || $qty <= 0) return false;

        // Use transaction-safe update
        try {
            $this->db->query("START TRANSACTION");
            $cur = $this->db->query("SELECT quantity FROM `" . DB_PREFIX . "product_variant` WHERE variant_id = " . $variant_id . " FOR UPDATE");
            if (!$cur->num_rows) { $this->db->query("ROLLBACK"); return false; }
            $current = (int)$cur->row['quantity'];
            $new = max(0, $current - $qty);
            $this->db->query("UPDATE `" . DB_PREFIX . "product_variant` SET quantity = " . (int)$new . ", date_modified = NOW() WHERE variant_id = " . $variant_id);
            $this->db->query("COMMIT");
            return $new;
        } catch (Exception $e) {
            try { $this->db->query("ROLLBACK"); } catch (Exception $x) {}
            return false;
        }
    }

    /**
     * Helper: build canonical option_key from an array of option_value_ids (ints)
     * - sorts the ids, implodes with '|' to ensure consistent keys
     */
    public function buildOptionKeyFromOptionValueIds(array $option_value_ids) {
        $ids = array();
        foreach ($option_value_ids as $v) {
            $v = (int)$v;
            if ($v > 0) $ids[] = $v;
        }
        sort($ids, SORT_NUMERIC);
        return implode('|', $ids);
    }
}
?>
