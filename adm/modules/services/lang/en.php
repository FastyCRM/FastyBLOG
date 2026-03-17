<?php
/**
 * FILE: /adm/modules/services/lang/en.php
 * ROLE: Словарь модуля services (EN).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле services.
 *  - Используется через services_t('services.*') или t('services.*').
 */

declare(strict_types=1);

return [
  'services.page_title' => 'Services',
  'services.search_placeholder' => 'Search categories and services',
  'services.action_reset_filter' => 'Reset filters',
  'services.action_add_category' => 'Add category',
  'services.action_add_service' => 'Add service',

  'services.section_categories_title' => 'Categories',
  'services.section_categories_hint' => 'Service grouping (shared search)',
  'services.section_services_title' => 'Services',
  'services.section_services_hint' => 'External form uses active services only',

  'services.col_category' => 'Category',
  'services.col_services_count' => 'Services',
  'services.col_service' => 'Service',
  'services.col_price' => 'Price',
  'services.col_duration' => 'Duration',
  'services.col_specialists' => 'Specialists',

  'services.action_edit_category' => 'Edit category',
  'services.action_edit_service' => 'Edit service',
  'services.action_delete' => 'Delete',

  'services.confirm_delete_category_with_services' => 'Delete category and all its services?',
  'services.confirm_delete_service_forever' => 'Delete service permanently?',

  'services.empty_categories' => 'No categories.',
  'services.empty_services' => 'No services.',
  'services.empty_services_in_category' => 'No services in this category.',

  'services.suggest_type_category' => 'Category',
  'services.suggest_type_service' => 'Service',

  'services.field_service_name' => 'Service name',
  'services.field_price' => 'Price',
  'services.field_duration_min' => 'Duration (min)',
  'services.field_category' => 'Category',
  'services.field_specialists' => 'Specialists',

  'services.placeholder_category_name' => 'Category name',
  'services.placeholder_service_name' => 'Service name',
  'services.placeholder_service_name_example' => 'Example: Haircut',
  'services.placeholder_price' => 'Price, ₽',
  'services.placeholder_duration_min' => 'Duration, min',
  'services.placeholder_specialist_search' => 'Search specialist',

  'services.option_uncategorized' => '— Uncategorized —',
  'services.no_specialists' => 'No specialists',

  'services.modal_default_title' => 'Modal',
  'services.modal_category_add_title' => 'New category',
  'services.modal_category_update_title' => 'Edit category',
  'services.modal_category_update_card_title' => 'Category #{id}',
  'services.modal_category_update_hint' => 'Edit category name',
  'services.modal_service_add_title' => 'New service',
  'services.modal_service_update_title' => 'Edit service',
  'services.modal_service_update_card_title' => 'Service #{id}',
  'services.modal_service_update_hint' => 'Edit name and relations',

  'services.btn_create' => 'Create',
  'services.btn_save' => 'Save',
  'services.btn_close' => 'Close',

  'services.dash' => '—',
  'services.duration_suffix_min' => 'min',

  'services.error_forbidden' => 'Access denied',
  'services.error_bad_id' => 'Invalid id',
  'services.error_category_not_found' => 'Category not found',
  'services.error_service_not_found' => 'Service not found',

  'services.flash_category_name_required' => 'Category name is required',
  'services.flash_category_duplicate' => 'Category with this name already exists',
  'services.flash_category_created' => 'Category created',
  'services.flash_category_create_error' => 'Category creation error',
  'services.flash_category_invalid_id' => 'Invalid category id',
  'services.flash_category_not_found' => 'Category not found',
  'services.flash_uncategorized_protected_delete' => 'Category "Uncategorized" is system and cannot be deleted',
  'services.flash_category_deleted' => 'Category deleted',
  'services.flash_category_delete_error' => 'Category delete error',
  'services.flash_uncategorized_auto_managed' => 'Category "Uncategorized" is managed automatically',
  'services.flash_category_enabled' => 'Category enabled',
  'services.flash_category_disabled' => 'Category disabled',
  'services.flash_category_toggle_error' => 'Category toggle error',
  'services.flash_category_invalid_or_empty' => 'Category not found or name is empty',
  'services.flash_category_updated' => 'Category updated',
  'services.flash_category_update_error' => 'Category update error',

  'services.flash_service_name_required' => 'Service name is required',
  'services.flash_service_duplicate' => 'Service with this name already exists',
  'services.flash_service_created' => 'Service created',
  'services.flash_service_create_error' => 'Service creation error',
  'services.flash_service_invalid_id' => 'Invalid service id',
  'services.flash_service_not_found' => 'Service not found',
  'services.flash_service_invalid_or_empty' => 'Service not found or name is empty',
  'services.flash_service_updated' => 'Service updated',
  'services.flash_service_update_error' => 'Service update error',
  'services.flash_service_deleted' => 'Service deleted',
  'services.flash_service_delete_error' => 'Service delete error',
  'services.flash_service_category_disabled' => 'Category is disabled. Enable it first',
  'services.flash_service_enabled' => 'Service enabled',
  'services.flash_service_disabled' => 'Service disabled',
  'services.flash_service_toggle_error' => 'Service toggle error',
];