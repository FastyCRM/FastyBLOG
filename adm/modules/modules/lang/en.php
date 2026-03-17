<?php
/**
 * FILE: /adm/modules/modules/lang/en.php
 * ROLE: Словарь модуля modules (EN).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле modules.
 *  - Используется через modules_t('modules.*') или t('modules.*').
 */

declare(strict_types=1);

return [
  'modules.page_title' => 'Module management',

  'modules.col_module' => 'Module',
  'modules.col_status' => 'Status',
  'modules.col_menu' => 'Menu',
  'modules.col_roles' => 'Roles',
  'modules.col_files' => 'Files',
  'modules.col_protection' => 'Protection',
  'modules.col_action' => 'Action',

  'modules.status_on' => 'ON',
  'modules.status_off' => 'OFF',
  'modules.menu_on' => 'menu',
  'modules.menu_off' => '—',

  'modules.files_missing' => 'No files',
  'modules.files_ok' => 'OK',

  'modules.protection_protected' => 'Protected',
  'modules.protection_none' => '—',

  'modules.action_enable' => 'Enable',
  'modules.action_disable' => 'Disable',

  'modules.flash_not_found' => 'Module not found',
  'modules.flash_protected' => 'This module is protected and cannot be disabled',
  'modules.flash_enabled' => 'Module "{name}" enabled',
  'modules.flash_disabled' => 'Module "{name}" disabled',
  'modules.flash_update_error' => 'Module update error',
];
