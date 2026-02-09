<?php
class ControllerExtensionModuleHeaderNotification extends Controller {
    public function index() {
        if (!$this->config->get('module_header_notification_status')) {
            return '';
        }

        $this->load->language('extension/module/header_notification');
        $this->load->model('extension/module/header_notification');

        $items = array();

        if ($this->config->get('module_header_notification_show_categories')) {
            $items[] = array(
                'label' => $this->language->get('text_total_categories'),
                'value' => $this->model_extension_module_header_notification->getCategoryTotal()
            );
        }
        if ($this->config->get('module_header_notification_show_manufacturers')) {
            $items[] = array(
                'label' => $this->language->get('text_total_manufacturers'),
                'value' => $this->model_extension_module_header_notification->getManufacturerTotal()
            );
        }
        if ($this->config->get('module_header_notification_show_products')) {
            $items[] = array(
                'label' => $this->language->get('text_total_products'),
                'value' => $this->model_extension_module_header_notification->getProductTotal()
            );
        }
        if ($this->config->get('module_header_notification_show_new_products')) {
            $items[] = array(
                'label' => $this->language->get('text_new_products_24h'),
                'value' => $this->model_extension_module_header_notification->getNewProductTotal24h()
            );
        }

        $custom = $this->config->get('module_header_notification_custom_items');
        if (is_array($custom)) {
            foreach ($custom as $row) {
                $enabled = !empty($row['enabled']);
                $label = isset($row['label']) ? trim((string)$row['label']) : '';
                $value = isset($row['value']) ? trim((string)$row['value']) : '';
                if ($enabled && $label !== '') {
                    $items[] = array(
                        'label' => $label,
                        'value' => $value
                    );
                }
            }
        }

        if (empty($items)) {
            return '';
        }

        $data['items'] = $items;
        return $this->load->view('extension/module/header_notification', $data);
    }
}
