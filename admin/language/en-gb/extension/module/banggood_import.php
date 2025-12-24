<?php
// Heading
$_['heading_title']    = 'Banggood Import';   

// Text
$_['text_extension']   = 'Extensions';
$_['text_success']     = 'Success: You have modified Banggood Import settings!';
$_['text_success_settings'] = 'Settings saved successfully!';
$_['text_success_import_category'] = 'Category import completed. Created: %d, Updated: %d';
$_['text_success_import_product_created'] = 'Product imported and created successfully.';
$_['text_success_import_product_updated'] = 'Product imported and updated successfully.';
$_['text_success_import_product_nochange'] = 'Product already exists and is up to date.';
$_['text_edit']        = 'Edit Banggood Import Module';
$_['text_enabled']     = 'Enabled';
$_['text_disabled']    = 'Disabled';
 
// Entry
$_['entry_status']             = 'Status';
$_['entry_base_url']           = 'Banggood API Base URL';
$_['entry_app_id']             = 'App ID';
$_['entry_app_secret']         = 'App Secret';
$_['entry_api_key']            = 'API Key';
$_['entry_default_language']   = 'Default Language';
$_['entry_default_currency']   = 'Default Currency';
$_['entry_category_id']        = 'Banggood Category ID';
$_['entry_max_products']       = 'Max Products (0 = all)';
$_['entry_product_url']        = 'Banggood Product URL';

// New: delete-missing mirror
$_['entry_delete_missing']      = 'Mirror categories (delete missing)';
$_['help_delete_missing']       = 'When enabled, categories that no longer exist on Banggood will be removed from the local Banggood category table on update.';

// New: option images
$_['entry_overwrite_option_images'] = 'Overwrite option images';
$_['help_overwrite_option_images']  = 'When enabled, Banggood POA images will overwrite existing OpenCart option value images during import.';

// New: product updates
$_['entry_update_minutes']      = 'Updated in last (minutes)';
$_['help_update_minutes']       = 'Range in minutes (max 21600 = 15 days).';
$_['button_fetch_updates']      = 'Fetch Updates';

// New: import by product id
$_['text_success_import_product_id'] = 'Product imported successfully.';

// Buttons
$_['button_save']              = 'Save';
$_['button_cancel']            = 'Cancel';
$_['button_import_category']   = 'Import Category';
$_['button_import_product_url'] = 'Import Product URL';

// Error
$_['error_permission']         = 'Warning: You do not have permission to modify Banggood Import!';
$_['error_category_id']        = 'Banggood Category ID required!';
$_['error_product_url']        = 'Banggood Product URL required!';
