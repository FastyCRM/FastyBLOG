<?php
/**
 * FILE: /adm/modules/requests/settings.php
 * ROLE: Параметры и константы модуля requests (БЕЗ логики)
 * NOTES:
 *  - settings.php НЕ содержит бизнес-логики
 *  - settings.php НЕ ACL и НЕ меню
 */

declare(strict_types=1);

/**
 * REQUESTS_TABLE — таблица заявок
 */
const REQUESTS_TABLE = 'requests';

/**
 * REQUESTS_SETTINGS_TABLE — таблица настроек модуля
 */
const REQUESTS_SETTINGS_TABLE = 'requests_settings';

/**
 * REQUESTS_HISTORY_TABLE — история изменений заявок
 */
const REQUESTS_HISTORY_TABLE = 'request_history';

/**
 * REQUESTS_COMMENTS_TABLE — комментарии по заявкам
 */
const REQUESTS_COMMENTS_TABLE = 'request_comments';

/**
 * REQUESTS_SERVICES_TABLE — услуги
 */
const REQUESTS_SERVICES_TABLE = 'services';

/**
 * REQUESTS_SPECIALTIES_TABLE — специализации
 */
const REQUESTS_SPECIALTIES_TABLE = 'specialties';

/**
 * REQUESTS_SPECIALTY_SERVICES_TABLE — связка специализаций и услуг
 */
const REQUESTS_SPECIALTY_SERVICES_TABLE = 'specialty_services';

/**
 * REQUESTS_USER_SERVICES_TABLE — связка специалистов и услуг
 */
const REQUESTS_USER_SERVICES_TABLE = 'user_services';

/**
 * REQUESTS_INVOICES_TABLE — счета по заявкам
 */
const REQUESTS_INVOICES_TABLE = 'request_invoices';

/**
 * REQUESTS_INVOICE_ITEMS_TABLE — позиции счета
 */
const REQUESTS_INVOICE_ITEMS_TABLE = 'request_invoice_items';

/**
 * REQUESTS_SCHEDULE_TABLE — расписание специалистов
 */
const REQUESTS_SCHEDULE_TABLE = 'specialist_schedule';

/**
 * REQUESTS_ALLOWED_DO — разрешённые действия модуля
 */
const REQUESTS_ALLOWED_DO = [
  'api_services',
  'api_request_add',
  'api_clients_lookup',
  'api_notify_status',
  'modal_add',
  'modal_settings',
  'add',
  'modal_view',
  'confirm',
  'take',
  'done',
  'reassign',
  'comment_add',
  'settings_update',
];

/**
 * REQUESTS_STATUS_NEW — новый статус
 */
const REQUESTS_STATUS_NEW = 'new';

/**
 * REQUESTS_STATUS_CONFIRMED — подтверждённый статус
 */
const REQUESTS_STATUS_CONFIRMED = 'confirmed';

/**
 * REQUESTS_STATUS_IN_WORK — в работе
 */
const REQUESTS_STATUS_IN_WORK = 'in_work';

/**
 * REQUESTS_STATUS_DONE — выполнено
 */
const REQUESTS_STATUS_DONE = 'done';

/**
 * REQUESTS_NOTIFY_STATUS_CHANGED — служебный статус "изменено"
 */
const REQUESTS_NOTIFY_STATUS_CHANGED = 'changed';

/**
 * REQUESTS_NOTIFY_STATUS_RESCHEDULED — служебный статус "перенесено"
 */
const REQUESTS_NOTIFY_STATUS_RESCHEDULED = 'rescheduled';

/**
 * REQUESTS_NOTIFY_STATUS_CANCELED — служебный статус "отменено"
 */
const REQUESTS_NOTIFY_STATUS_CANCELED = 'canceled';

/**
 * REQUESTS_TG_EVENT_SPEC_NEW — специалисту: новая заявка на него
 */
const REQUESTS_TG_EVENT_SPEC_NEW = 'requests_specialist_new';

/**
 * REQUESTS_TG_EVENT_SPEC_CONFIRMED — специалисту: подтвержденная заявка
 */
const REQUESTS_TG_EVENT_SPEC_CONFIRMED = 'requests_specialist_confirmed';

/**
 * REQUESTS_TG_EVENT_SPEC_CHANGED — специалисту: измененная/перенесенная/отмененная
 */
const REQUESTS_TG_EVENT_SPEC_CHANGED = 'requests_specialist_changed';

/**
 * REQUESTS_TG_EVENT_SPEC_REMINDER_15M — специалисту: напоминание за 15 минут
 */
const REQUESTS_TG_EVENT_SPEC_REMINDER_15M = 'requests_specialist_reminder_15m';

/**
 * REQUESTS_TG_EVENT_SPEC_REMINDER_CLOSE_5M — специалисту: напоминание закрыть через 5 минут
 */
const REQUESTS_TG_EVENT_SPEC_REMINDER_CLOSE_5M = 'requests_specialist_reminder_close_5m';

/**
 * REQUESTS_TG_EVENT_MGR_NEW — менеджеру/админу: новая заявка
 */
const REQUESTS_TG_EVENT_MGR_NEW = 'requests_manager_new';

/**
 * REQUESTS_TG_EVENT_MGR_CONFIRMED — менеджеру/админу: подтвержденная заявка
 */
const REQUESTS_TG_EVENT_MGR_CONFIRMED = 'requests_manager_confirmed';

/**
 * REQUESTS_TG_EVENT_MGR_CHANGED — менеджеру/админу: измененная заявка
 */
const REQUESTS_TG_EVENT_MGR_CHANGED = 'requests_manager_changed';

/**
 * REQUESTS_TG_EVENT_MGR_ACCEPTED — менеджеру/админу: заявка принята в работу
 */
const REQUESTS_TG_EVENT_MGR_ACCEPTED = 'requests_manager_accepted';

/**
 * REQUESTS_TG_EVENT_MGR_DONE — менеджеру/админу: заявка выполнена
 */
const REQUESTS_TG_EVENT_MGR_DONE = 'requests_manager_done';

/**
 * REQUESTS_TG_EVENT_CLIENT_ACCEPTED — клиенту: заявка принята в работу
 */
const REQUESTS_TG_EVENT_CLIENT_ACCEPTED = 'requests_client_accepted';

/**
 * REQUESTS_TG_EVENT_CLIENT_CONFIRMED — клиенту: заявка подтверждена
 */
const REQUESTS_TG_EVENT_CLIENT_CONFIRMED = 'requests_client_confirmed';

/**
 * REQUESTS_TG_EVENT_CLIENT_CHANGED — клиенту: заявка изменена/перенесена/отменена
 */
const REQUESTS_TG_EVENT_CLIENT_CHANGED = 'requests_client_changed';

/**
 * REQUESTS_TG_EVENT_CLIENT_REMINDER_60M — клиенту: напоминание за 60 минут
 */
const REQUESTS_TG_EVENT_CLIENT_REMINDER_60M = 'requests_client_reminder_60m';

/**
 * REQUESTS_TG_EVENT_CLIENT_THANKS — клиенту: благодарность после визита
 */
const REQUESTS_TG_EVENT_CLIENT_THANKS = 'requests_client_thanks';
