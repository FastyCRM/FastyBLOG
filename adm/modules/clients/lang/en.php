<?php
/**
 * FILE: /adm/modules/clients/lang/en.php
 * ROLE: Словарь модуля clients (EN).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле clients.
 *  - Используется через clients_t('clients.*') или t('clients.*').
 */

declare(strict_types=1);

return [
  'clients.page_title' => 'Clients',
  'clients.search_label' => 'Client search',
  'clients.search_placeholder' => 'Name / phone / INN',
  'clients.search_empty' => 'Nothing found.',
  'clients.search_reset' => 'Reset',

  'clients.action_settings' => 'Settings',
  'clients.action_add_client' => 'Add client',
  'clients.action_personal_file' => 'Personal file',
  'clients.action_edit' => 'Edit',
  'clients.action_block' => 'Block',
  'clients.action_unblock' => 'Unblock',
  'clients.action_reset_password' => 'Reset password (email)',

  'clients.col_client' => 'Client',
  'clients.col_phone_login' => 'Phone (login)',
  'clients.col_email' => 'Email',
  'clients.col_status' => 'Status',
  'clients.col_created' => 'Created',
  'clients.empty_list' => 'No clients.',
  'clients.dash' => '-',

  'clients.status_active' => 'Active',
  'clients.status_blocked' => 'Blocked',

  'clients.modal_add_title' => 'Add client',
  'clients.modal_add_card_title' => 'New client',
  'clients.modal_add_hint' => 'Phone is login. Temporary password is generated on create.',
  'clients.modal_update_title' => 'Edit client',
  'clients.modal_update_card_title' => 'Client #{id}',
  'clients.modal_update_hint' => 'Edit client data.',

  'clients.modal_settings_title' => 'Client settings',
  'clients.modal_settings_card_title' => 'Client settings (test)',
  'clients.modal_settings_hint' => 'Danger zone. Test environment only.',
  'clients.modal_settings_text' => 'Action removes all records from clients. Requests and other module data are not removed.',
  'clients.modal_settings_clear_button' => 'Clear all clients (test)',
  'clients.modal_settings_confirm' => 'TEST MODE: delete ALL clients? This action cannot be undone.',

  'clients.field_first_name_required' => 'First name *',
  'clients.field_last_name' => 'Last name',
  'clients.field_middle_name' => 'Middle name',
  'clients.field_phone_login_required' => 'Phone number (login) *',
  'clients.field_email' => 'Email',
  'clients.field_inn' => 'INN',
  'clients.field_birth_date' => 'Birth date',
  'clients.field_photo' => 'Photo',
  'clients.field_status' => 'Status',
  'clients.photo_uploaded' => 'Photo uploaded',

  'clients.btn_save' => 'Save',
  'clients.btn_cancel' => 'Cancel',

  'clients.error_bad_id' => 'Invalid ID',
  'clients.error_client_not_found' => 'Client not found',
  'clients.error_forbidden' => 'Forbidden',
  'clients.error_access_denied' => 'Access denied',

  'clients.flash_required_name_phone' => 'Name and phone are required',
  'clients.flash_duplicate_phone' => 'Client with this phone already exists (ID: {id})',
  'clients.flash_client_created_with_email' => 'Client created. Temporary password (email sending disabled for now): {password}',
  'clients.flash_client_created' => 'Client created. Temporary password: {password}',
  'clients.flash_create_error' => 'Client creation error',

  'clients.flash_invalid_id' => 'Invalid ID',
  'clients.flash_phone_used_by_other' => 'Phone is already used by another client (ID: {id})',
  'clients.flash_client_updated' => 'Client updated',
  'clients.flash_update_error' => 'Client update error',

  'clients.flash_client_not_found' => 'Client not found',
  'clients.flash_client_status_updated' => 'Client status: {status}',
  'clients.flash_status_change_error' => 'Status change error',

  'clients.flash_invalid_client' => 'Invalid client',
  'clients.flash_no_email_for_reset' => 'Client has no email, reset is impossible',
  'clients.flash_password_sent' => 'New password was sent to client email',
  'clients.flash_password_mail_failed' => 'Email was not sent. Temporary password: {password}',
  'clients.flash_password_reset_error' => 'Client password reset error',

  'clients.flash_confirm_required' => 'Operation confirmation is required',
  'clients.flash_clear_done' => 'Test cleanup complete. Deleted clients: {count}',
  'clients.flash_clear_failed' => 'Failed to clear clients in test mode',

  'clients.email_subject_new_password' => 'CRM2026: new password',
  'clients.email_body_new_password' => "Your new temporary password: {password}\n\nWe recommend changing your password after login.",

  'clients.client_fallback' => 'Client #{id}',
  'clients.inn_prefix' => 'INN',
  'clients.js_modal_title' => 'Modal',
];
