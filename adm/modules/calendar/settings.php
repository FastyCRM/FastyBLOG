<?php
/**
 * FILE: /adm/modules/calendar/settings.php
 * ROLE: Константы и таблицы модуля calendar (для ядра)
 */

declare(strict_types=1);

/**
 * CALENDAR_REQUESTS_TABLE — заявки (основная таблица)
 */
const CALENDAR_REQUESTS_TABLE = 'requests';

/**
 * CALENDAR_REQUESTS_SETTINGS_TABLE — настройки заявок
 */
const CALENDAR_REQUESTS_SETTINGS_TABLE = 'requests_settings';

/**
 * CALENDAR_USER_SETTINGS_TABLE — персональные настройки календаря
 */
const CALENDAR_USER_SETTINGS_TABLE = 'calendar_user_settings';

/**
 * CALENDAR_USERS_TABLE — пользователи
 */
const CALENDAR_USERS_TABLE = 'users';

/**
 * CALENDAR_ROLES_TABLE — роли
 */
const CALENDAR_ROLES_TABLE = 'roles';

/**
 * CALENDAR_USER_ROLES_TABLE — связь ролей
 */
const CALENDAR_USER_ROLES_TABLE = 'user_roles';

/**
 * CALENDAR_SCHEDULE_TABLE — график специалистов
 */
const CALENDAR_SCHEDULE_TABLE = 'specialist_schedule';

/**
 * CALENDAR_DEFAULT_DAYS — дней по умолчанию (режим специалиста)
 */
const CALENDAR_DEFAULT_DAYS = 4;

/**
 * CALENDAR_SLOT_HEIGHT_PX — высота строки/слота в пикселях
 */
const CALENDAR_SLOT_HEIGHT_PX = 40;

/**
 * CALENDAR_ALLOWED_DO — допустимые действия (не API)
 */
const CALENDAR_ALLOWED_DO = [
  'modal_settings',
  'modal_manager_settings',
  'settings_update',
];

/**
 * CALENDAR_ALLOWED_API_DO — допустимые API действия
 */
const CALENDAR_ALLOWED_API_DO = [
  'api_specialists',
  'api_slots_day',
  'api_slots_nearest',
];
