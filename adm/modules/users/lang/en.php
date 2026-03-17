<?php
/**
 * FILE: /adm/modules/users/lang/en.php
 * ROLE: Словарь модуля users (EN).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле users.
 *  - Используется через users_t('users.*') или t('users.*').
 */

declare(strict_types=1);

return [
  'users.page_title' => 'Users',
  'users.col_user' => 'User',
  'users.col_phone' => 'Phone',
  'users.col_roles' => 'Roles',
  'users.col_status' => 'Status',
  'users.action_add_user' => 'Add user',
  'users.action_edit' => 'Edit',
  'users.action_block' => 'Block',
  'users.action_unblock' => 'Unblock',
  'users.action_reset_password' => 'Reset password',
  'users.empty_list' => 'No users.',
  'users.dash' => '—',

  'users.status_active' => 'active',
  'users.status_blocked' => 'blocked',

  'users.modal_add_title' => 'Add user',
  'users.modal_add_card_title' => 'New user',
  'users.modal_add_hint' => 'Manager can create only user role. Admin can assign roles.',
  'users.modal_update_title' => 'Edit user',
  'users.modal_user_title' => 'User #{id}',
  'users.modal_update_hint_admin' => 'Admin can change roles.',
  'users.modal_update_hint_manager' => 'Manager cannot manage roles.',

  'users.field_name' => 'Name',
  'users.field_phone' => 'Phone',
  'users.field_email' => 'Email',
  'users.field_email_for_reset' => 'Email (for password reset)',
  'users.field_status' => 'Status',
  'users.field_ui_theme' => 'UI theme',
  'users.field_roles' => 'Roles',

  'users.work_mode_title' => 'Work schedule (calendar)',
  'users.work_mode_hint' => 'Settings for specialist calendar.',
  'users.table_day' => 'Day',
  'users.table_day_off' => 'Day off',
  'users.table_from' => 'From',
  'users.table_to' => 'To',
  'users.table_break_from' => 'Lunch from',
  'users.table_break_to' => 'Lunch to',
  'users.day_off' => 'day off',
  'users.weekday_1' => 'Mon',
  'users.weekday_2' => 'Tue',
  'users.weekday_3' => 'Wed',
  'users.weekday_4' => 'Thu',
  'users.weekday_5' => 'Fri',
  'users.weekday_6' => 'Sat',
  'users.weekday_7' => 'Sun',

  'users.btn_save' => 'Save',
  'users.btn_cancel' => 'Cancel',
  'users.btn_close' => 'Close',

  'users.error_bad_id' => 'Bad ID',
  'users.error_user_not_found' => 'User not found',
  'users.flash_invalid_user' => 'Invalid user',
  'users.flash_required_fields' => 'Name, phone and email are required',
  'users.flash_role_assign_failed' => 'Failed to assign role to user',
  'users.flash_user_created' => 'User created. Temporary password: {password}',
  'users.flash_user_updated' => 'User updated',
  'users.flash_user_create_error' => 'User creation error',
  'users.flash_user_update_error' => 'User update error',
  'users.flash_self_block_forbidden' => 'You cannot block yourself',
  'users.flash_status_updated' => 'Status updated: {status}',
  'users.flash_status_change_error' => 'Status change error',
  'users.flash_self_reset_blocked' => 'Your own password is changed separately (later). This action is only for other users.',
  'users.flash_no_email_for_reset' => 'User has no email, reset is impossible',
  'users.flash_password_sent' => 'New password sent to email',
  'users.flash_password_mail_failed' => 'Email was not sent. Temporary password: {password}',
  'users.flash_password_reset_error' => 'Password reset error',

  'users.email_subject_new_password' => 'CRM2026: new password',
  'users.email_body_new_password' => "Your new temporary password: {password}\n\nWe recommend changing your password after login.",
];
