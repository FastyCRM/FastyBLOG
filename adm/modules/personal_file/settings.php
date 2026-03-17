<?php
/**
 * FILE: /adm/modules/personal_file/settings.php
 * ROLE: Паспорт модуля personal_file (ТОЛЬКО константы/параметры)
 * NOTES:
 *  - settings.php НЕ содержит бизнес-логики
 *  - settings.php НЕ ACL и НЕ меню
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * Таблицы
 */
const PERSONAL_FILE_CLIENTS_TABLE = 'clients';
const PERSONAL_FILE_REQUESTS_TABLE = 'requests';
const PERSONAL_FILE_REQUEST_COMMENTS_TABLE = 'request_comments';
const PERSONAL_FILE_REQUEST_INVOICES_TABLE = 'request_invoices';
const PERSONAL_FILE_REQUEST_INVOICE_ITEMS_TABLE = 'request_invoice_items';

const PERSONAL_FILE_NOTES_TABLE = 'personal_file_notes';
const PERSONAL_FILE_NOTE_FILES_TABLE = 'personal_file_note_files';
const PERSONAL_FILE_ACCESS_TABLE = 'personal_file_access';
const PERSONAL_FILE_ACCESS_TYPES_TABLE = 'personal_file_access_types';
const PERSONAL_FILE_ACCESS_TTLS_TABLE = 'personal_file_access_ttls';
const PERSONAL_FILE_ACCESS_REMINDERS_TABLE = 'personal_file_access_reminders';

/**
 * Разрешённые действия (do)
 */
const PERSONAL_FILE_ALLOWED_DO = [
  'modal_settings',
  'settings_type_add',
  'settings_type_update',
  'settings_type_delete',
  'settings_ttl_add',
  'settings_ttl_update',
  'settings_ttl_delete',
  'client_update',
  'note_add',
  'notes_pdf',
  'access_add',
  'access_delete',
  'access_reveal',
  'file_get',
  'api_link',
  'api_brief',
  'api_notes',
  'api_services',
  'api_accesses',
];
