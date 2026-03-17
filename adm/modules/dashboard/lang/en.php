<?php
/**
 * FILE: /adm/modules/dashboard/lang/en.php
 * ROLE: Словарь модуля dashboard (EN).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле dashboard.
 *  - Используется через t('dashboard.*').
 */

declare(strict_types=1);

return [
  'dashboard.profile_label_name' => 'Name',
  'dashboard.profile_label_phone' => 'Phone',
  'dashboard.profile_label_email' => 'Email',
  'dashboard.profile_email_empty' => 'not specified',
  'dashboard.profile_label_theme' => 'Theme',

  'dashboard.notifications_aria' => 'Notifications',
  'dashboard.notifications_title' => 'Unread notifications',
  'dashboard.notifications_hint_stub' => 'Stub until a real source is connected',
  'dashboard.notifications_empty' => 'No new notifications',
  'dashboard.notification_fallback_title' => 'Notification',

  'dashboard.profile_settings_aria' => 'Profile settings',
  'dashboard.system_card_fallback_title' => 'Block',
  'dashboard.system_card_fallback_action' => 'Open',

  'dashboard.recent_done_title' => 'Last 4 completed requests',
  'dashboard.requests_all' => 'All requests',
  'dashboard.table_client' => 'Client',
  'dashboard.table_service' => 'Service',
  'dashboard.table_date' => 'Date',
  'dashboard.table_amount' => 'Amount',
  'dashboard.recent_done_empty' => 'No completed requests found.',

  'dashboard.salary_title' => 'Salary calculation',
  'dashboard.salary_hint_period' => '{percent}% from completed requests for the last {days} days',
  'dashboard.salary_done_count' => 'Completed requests:',
  'dashboard.salary_done_sum' => 'Completed amount:',
  'dashboard.salary_piecework' => 'Piecework:',
  'dashboard.salary_piecework_on' => 'enabled',
  'dashboard.salary_piecework_off' => 'disabled',

  'dashboard.modal_profile_edit_title' => 'Edit profile',
  'dashboard.modal_profile_edit_hint' => 'Data is updated for the current user.',
  'dashboard.field_last_name' => 'Last name',
  'dashboard.field_name' => 'First name',
  'dashboard.field_middle_name' => 'Middle name',
  'dashboard.field_phone' => 'Phone',
  'dashboard.field_email' => 'Email',
  'dashboard.field_theme' => 'Theme',

  'dashboard.work_time_title' => 'Working hours',
  'dashboard.work_time_hint' => 'Same settings as in users module (manager/admin).',
  'dashboard.work_lead_minutes' => 'Break between appointments (min)',
  'dashboard.table_day' => 'Day',
  'dashboard.table_day_off' => 'Day off',
  'dashboard.table_from' => 'From',
  'dashboard.table_to' => 'To',
  'dashboard.table_break_from' => 'Break from',
  'dashboard.table_break_to' => 'Break to',
  'dashboard.day_off' => 'day off',

  'dashboard.tg_title' => 'Telegram: system notifications',
  'dashboard.tg_hint' => 'Personal on/off for the current user.',
  'dashboard.tg_events_empty' => 'Events are not configured yet.',
  'dashboard.tg_globally_disabled' => '(globally disabled)',

  'dashboard.save' => 'Save',
  'dashboard.close' => 'Close',
  'dashboard.modal_settings_title' => 'Profile settings',

  'dashboard.flash_user_not_defined' => 'User is not defined',
  'dashboard.flash_profile_required' => 'Name, phone and email are required',
  'dashboard.flash_profile_saved' => 'Profile saved',
  'dashboard.flash_profile_save_error' => 'Failed to save profile',

  'dashboard.weekday_1' => 'Mon',
  'dashboard.weekday_2' => 'Tue',
  'dashboard.weekday_3' => 'Wed',
  'dashboard.weekday_4' => 'Thu',
  'dashboard.weekday_5' => 'Fri',
  'dashboard.weekday_6' => 'Sat',
  'dashboard.weekday_7' => 'Sun',

  'dashboard.user_not_defined' => 'User is not defined',
  'dashboard.user_with_id' => 'User #{id}',

  'dashboard.stub_notify_title_1' => 'New CRM2026 version',
  'dashboard.stub_notify_text_1' => 'A real system notifications feed will appear soon.',
  'dashboard.stub_notify_time_1' => 'today',
  'dashboard.stub_notify_title_2' => 'Scheduled maintenance',
  'dashboard.stub_notify_text_2' => 'Stub: service notifications will appear here.',
  'dashboard.stub_notify_time_2' => 'yesterday',
  'dashboard.stub_notify_title_3' => 'Reminder',
  'dashboard.stub_notify_text_3' => 'Check request statuses before the end of your shift.',
  'dashboard.stub_notify_time_3' => '2 days ago',

  'dashboard.stub_system_title_1' => 'System notifications',
  'dashboard.stub_system_text_1' => 'Infrastructure events feed. Currently a stub.',
  'dashboard.stub_system_action_1' => 'Open feed',
  'dashboard.stub_system_title_2' => 'Developer channel',
  'dashboard.stub_system_text_2' => 'Communication channel will be connected in the next release (stub for now).',
  'dashboard.stub_system_action_2' => 'Open channel',
  'dashboard.stub_system_title_3' => 'Discounts and promotions',
  'dashboard.stub_system_text_3' => 'Promo block is a stub until marketing integration is ready.',
  'dashboard.stub_system_action_3' => 'View promotions',
];
