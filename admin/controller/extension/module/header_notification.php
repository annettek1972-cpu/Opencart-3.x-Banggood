<?php
class ControllerExtensionModuleHeaderNotification extends Controller {
    private $error = array();

    public function index() {
        $this->load->language('extension/module/header_notification');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_header_notification', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
            return;
        }

        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['text_add'] = $this->language->get('text_add');
        $data['text_remove'] = $this->language->get('text_remove');
        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');

        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_show_categories'] = $this->language->get('entry_show_categories');
        $data['entry_show_manufacturers'] = $this->language->get('entry_show_manufacturers');
        $data['entry_show_products'] = $this->language->get('entry_show_products');
        $data['entry_show_new_products'] = $this->language->get('entry_show_new_products');
        $data['entry_custom_items'] = $this->language->get('entry_custom_items');
        $data['entry_custom_label'] = $this->language->get('entry_custom_label');
        $data['entry_custom_value'] = $this->language->get('entry_custom_value');
        $data['entry_custom_enabled'] = $this->language->get('entry_custom_enabled');

        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/header_notification', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link('extension/module/header_notification', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

        $fields = array(
            'module_header_notification_status',
            'module_header_notification_show_categories',
            'module_header_notification_show_manufacturers',
            'module_header_notification_show_products',
            'module_header_notification_show_new_products',
            'module_header_notification_custom_items'
        );
        foreach ($fields as $field) {
            if (isset($this->request->post[$field])) {
                $data[$field] = $this->request->post[$field];
            } else {
                $data[$field] = $this->config->get($field);
            }
        }
        if (!is_array($data['module_header_notification_custom_items'])) {
            $data['module_header_notification_custom_items'] = array();
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/header_notification', $data));
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/module/header_notification')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }
}
