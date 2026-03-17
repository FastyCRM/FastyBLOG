<?php
/**
 * FILE: /adm/modules/dashboard/settings.php
 * ROLE: Паспорт модуля dashboard (константы/параметры, без логики)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * DASHBOARD_MODULE_CODE — код модуля
 */
const DASHBOARD_MODULE_CODE = 'dashboard';

/**
 * DASHBOARD_ALLOWED_DO — разрешённые do для dashboard
 */
const DASHBOARD_ALLOWED_DO = [
  'modal_settings',
  'profile_update',
];

return [
  'code' => 'dashboard',
  'name' => 'Главная',
  'icon' => '⌂',
  'has_settings' => true,
];