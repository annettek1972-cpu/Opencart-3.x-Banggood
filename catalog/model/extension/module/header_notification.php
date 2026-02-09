<?php
class ModelExtensionModuleHeaderNotification extends Model {
    public function getCategoryTotal() {
        try {
            $q = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "category` WHERE `status` = 1");
            return isset($q->row['total']) ? (int)$q->row['total'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public function getManufacturerTotal() {
        try {
            $q = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "manufacturer`");
            return isset($q->row['total']) ? (int)$q->row['total'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public function getProductTotal() {
        try {
            $q = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "product` WHERE `status` = 1");
            return isset($q->row['total']) ? (int)$q->row['total'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public function getNewProductTotal24h() {
        try {
            $q = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "product` WHERE `date_added` >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
            return isset($q->row['total']) ? (int)$q->row['total'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }
}
