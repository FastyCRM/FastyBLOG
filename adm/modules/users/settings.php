<?php
/**
 * FILE: /adm/modules/users/settings.php
 * ROLE: Паспорт модуля users (метаданные, константы, allowed do)
 * CONNECTIONS:
 *  - НЕТ (settings.php не содержит логики и не подключает БД)
 *
 * NOTES:
 *  - roles/menu/enabled — источник истины: таблица modules в БД
 *  - settings.php НЕ ACL и НЕ меню
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * USERS_MODULE_CODE — код модуля
 */
const USERS_MODULE_CODE = 'users';

/**
 * USERS_ALLOWED_DO — разрешённые действия (do)
 */
const USERS_ALLOWED_DO = [
  'add',
  'update',
  'toggle',
  'reset_password',

  'modal_add',
  'modal_update',
];
