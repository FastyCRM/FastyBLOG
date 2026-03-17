<?php
/**
 * FILE: /adm/modules/services/settings.php
 * ROLE: Параметры и константы модуля services (БЕЗ логики)
 */

declare(strict_types=1);

/**
 * SERVICES_TABLE — услуги
 */
const SERVICES_TABLE = 'services';

/**
 * SERVICES_SPECIALTIES_TABLE — категории (specialties)
 */
const SERVICES_SPECIALTIES_TABLE = 'specialties';

/**
 * SERVICES_SPECIALTY_SERVICES_TABLE — связка категорий и услуг
 */
const SERVICES_SPECIALTY_SERVICES_TABLE = 'specialty_services';

/**
 * SERVICES_USER_SERVICES_TABLE — связка специалистов и услуг
 */
const SERVICES_USER_SERVICES_TABLE = 'user_services';

/**
 * SERVICES_SETTINGS_TABLE — таблица настроек модуля заявок (use_specialists)
 */
const SERVICES_SETTINGS_TABLE = 'requests_settings';

/**
 * SERVICES_UNCATEGORIZED_CODE — системный код категории "Без категории"
 */
const SERVICES_UNCATEGORIZED_CODE = 'uncategorized';

/**
 * SERVICES_UNCATEGORIZED_NAME — системное имя категории "Без категории"
 */
const SERVICES_UNCATEGORIZED_NAME = 'Без категории';

/**
 * SERVICES_ALLOWED_DO — разрешённые действия модуля
 */
const SERVICES_ALLOWED_DO = [
  'modal_category_add',
  'category_add',
  'modal_category_update',
  'category_update',
  'category_delete',

  'modal_service_add',
  'service_add',
  'modal_service_update',
  'service_update',
  'service_delete',
];
