<?php
/**
 * FILE: /adm/modules/modules/lang/ru.php
 * ROLE: Словарь модуля modules (RU).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле modules.
 *  - Используется через modules_t('modules.*') или t('modules.*').
 */

declare(strict_types=1);

return [
  'modules.page_title' => 'Управление модулями',

  'modules.col_module' => 'Модуль',
  'modules.col_status' => 'Статус',
  'modules.col_menu' => 'Menu',
  'modules.col_roles' => 'Роли',
  'modules.col_files' => 'Файлы',
  'modules.col_protection' => 'Защита',
  'modules.col_action' => 'Действие',

  'modules.status_on' => 'ON',
  'modules.status_off' => 'OFF',
  'modules.menu_on' => 'menu',
  'modules.menu_off' => '—',

  'modules.files_missing' => 'Нет файлов',
  'modules.files_ok' => 'OK',

  'modules.protection_protected' => 'Защищено',
  'modules.protection_none' => '—',

  'modules.action_enable' => 'Включить',
  'modules.action_disable' => 'Выключить',

  'modules.flash_not_found' => 'Модуль не найден',
  'modules.flash_protected' => 'Этот модуль защищен и не может быть выключен',
  'modules.flash_enabled' => 'Модуль «{name}» включен',
  'modules.flash_disabled' => 'Модуль «{name}» выключен',
  'modules.flash_update_error' => 'Ошибка изменения модуля',
];
