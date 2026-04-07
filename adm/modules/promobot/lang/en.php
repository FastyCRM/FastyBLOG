<?php
/**
 * FILE: /adm/modules/promobot/lang/en.php
 * ROLE: EN dictionary for promobot module.
 */

return [
  'promobot.page_title' => 'Promobot',
  'promobot.page_hint' => 'Bot replies to incoming messages by keywords and returns promo codes.',

  'promobot.install_title' => 'Database setup required',
  'promobot.install_hint' => 'Apply module SQL schema or click install.',
  'promobot.action_install_db' => 'Create DB',

  'promobot.section_switch' => 'Bot selection',
  'promobot.field_current_bot' => 'Current bot',
  'promobot.no_bots' => 'No bots found',
  'promobot.action_select' => 'Open',
  'promobot.action_add_bot' => 'Add bot',
  'promobot.action_log_disable' => 'Disable logging',
  'promobot.action_log_enable' => 'Enable logging',

  'promobot.section_bots' => 'Bots',
  'promobot.col_name' => 'Name',
  'promobot.col_platform' => 'Platform',
  'promobot.col_status' => 'Status',
  'promobot.col_webhook' => 'Webhook',
  'promobot.status_on' => 'ON',
  'promobot.status_off' => 'OFF',

  'promobot.section_channels' => 'Chats and channels',
  'promobot.channels_hint' => 'Bind a chat with /bind CODE. Without binding, replies are not sent.',
  'promobot.action_bind_code' => 'Generate bind code',
  'promobot.col_chat' => 'Chat',
  'promobot.col_chat_type' => 'Type',
  'promobot.no_channels' => 'No bindings yet',

  'promobot.section_promos' => 'Promos',
  'promobot.promos_hint' => 'Keywords are comma-separated. The bot searches substrings in incoming messages.',
  'promobot.action_add_promo' => 'Add promo',
  'promobot.search_promos_label' => 'Keyword search',
  'promobot.search_promos_placeholder' => 'Enter a keyword',
  'promobot.search_promos_empty' => 'Nothing found for this search',
  'promobot.col_keywords' => 'Keywords',
  'promobot.col_response' => 'Response',
  'promobot.no_promos' => 'No promos added',

  'promobot.section_users' => 'Users',
  'promobot.field_user' => 'User',
  'promobot.action_attach' => 'Assign',
  'promobot.col_user' => 'User',
  'promobot.col_roles' => 'Roles',
  'promobot.no_users' => 'No users assigned',

  'promobot.action_edit' => 'Edit',
  'promobot.action_disable' => 'Disable',
  'promobot.action_enable' => 'Enable',
  'promobot.action_webhook_set' => 'Set webhook',
  'promobot.action_delete' => 'Delete',
  'promobot.action_unbind' => 'Unbind',
  'promobot.action_detach' => 'Detach',
  'promobot.action_save' => 'Save',

  'promobot.confirm_bot_delete' => 'Delete bot and all related data?',
  'promobot.confirm_channel_unbind' => 'Unbind this chat?',
  'promobot.confirm_promo_delete' => 'Delete promo?',
  'promobot.confirm_user_detach' => 'Detach user from bot?',

  'promobot.platform_tg' => 'Telegram',
  'promobot.platform_max' => 'MAX',

  'promobot.field_bot_name' => 'Bot name',
  'promobot.field_platform' => 'Platform',
  'promobot.field_enabled' => 'Enabled',
  'promobot.field_bot_token' => 'Bot token',
  'promobot.field_webhook_secret' => 'Webhook secret',
  'promobot.field_webhook_url' => 'Webhook URL',
  'promobot.field_max_api_key' => 'MAX API key',
  'promobot.field_max_base_url' => 'MAX base URL',
  'promobot.field_max_send_path' => 'MAX send path',

  'promobot.field_keywords' => 'Keywords',
  'promobot.field_keywords_placeholder' => 'magnit, magnit cosmetics, magnit near home',
  'promobot.field_response_text' => 'Response text',
  'promobot.field_active' => 'Active',

  'promobot.modal_bot_add_title' => 'Add bot',
  'promobot.modal_bot_update_title' => 'Edit bot',
  'promobot.modal_promo_add_title' => 'Add promo',
  'promobot.modal_promo_update_title' => 'Edit promo',
  'promobot.bot_add_hint' => 'After creation, open the bot and fill tokens/keys.',

  'promobot.flash_access_denied' => 'Access denied.',
  'promobot.flash_install_tables_empty' => 'Tables list is empty.',
  'promobot.flash_install_no_need' => 'Tables already exist.',
  'promobot.flash_install_done' => 'Install done. Created: {created}, existing: {existing}.',
  'promobot.flash_install_error' => 'Install error: {error}',
  'promobot.error_db_name' => 'Failed to detect DB name.',
  'promobot.error_install_sql_missing' => 'install.sql not found.',
  'promobot.error_install_sql_empty' => 'install.sql is empty.',
  'promobot.error_install_missing_create' => 'Missing CREATE statements for tables',
  'promobot.error_schema_missing' => 'Module tables are not installed.',

  'promobot.flash_log_enabled' => 'Logging enabled.',
  'promobot.flash_log_disabled' => 'Logging disabled.',

  'promobot.flash_bot_not_found' => 'Bot not found.',
  'promobot.flash_bot_name_required' => 'Bot name is required.',
  'promobot.flash_bot_added' => 'Bot added.',
  'promobot.flash_bot_updated' => 'Bot updated.',
  'promobot.flash_bot_enabled' => 'Bot enabled.',
  'promobot.flash_bot_disabled' => 'Bot disabled.',
  'promobot.flash_bot_deleted' => 'Bot deleted.',
  'promobot.flash_bot_delete_error' => 'Bot delete error: {error}',
  'promobot.flash_bot_platform_mismatch' => 'Bot platform mismatch.',
  'promobot.flash_bot_token_empty' => 'Fill bot token.',
  'promobot.flash_webhook_set_ok' => 'Webhook set.',
  'promobot.flash_webhook_set_fail' => 'Failed to set webhook.',

  'promobot.flash_bind_code' => 'Bind code: {code}. Valid until {expires_at}.',
  'promobot.flash_bind_code_fail' => 'Failed to generate bind code.',

  'promobot.flash_channel_not_found' => 'Chat not found.',
  'promobot.flash_channel_enabled' => 'Chat enabled.',
  'promobot.flash_channel_disabled' => 'Chat disabled.',
  'promobot.flash_channel_unbound' => 'Chat unbound.',

  'promobot.flash_promo_not_found' => 'Promo not found.',
  'promobot.flash_promo_required' => 'Fill keywords and response text.',
  'promobot.flash_promo_added' => 'Promo added.',
  'promobot.flash_promo_updated' => 'Promo updated.',
  'promobot.flash_promo_enabled' => 'Promo enabled.',
  'promobot.flash_promo_disabled' => 'Promo disabled.',
  'promobot.flash_promo_deleted' => 'Promo deleted.',

  'promobot.flash_user_attach_fail' => 'Failed to assign user.',
  'promobot.flash_user_attached' => 'User assigned.',
  'promobot.flash_user_detach_fail' => 'Failed to detach user.',
  'promobot.flash_user_detached' => 'User detached.',

  'promobot.bind_ok' => 'Chat linked.',
  'promobot.bind_fail' => 'Failed to link chat.',

  'promobot.dash' => '-',
];
