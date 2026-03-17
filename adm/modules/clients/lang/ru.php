<?php
/**
 * FILE: /adm/modules/clients/lang/ru.php
 * ROLE: Словарь модуля clients (RU).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле clients.
 *  - Используется через clients_t('clients.*') или t('clients.*').
 */

declare(strict_types=1);

return [
  'clients.page_title' => 'Клиенты',
  'clients.search_label' => 'Поиск клиента',
  'clients.search_placeholder' => 'Имя / телефон / ИНН',
  'clients.search_empty' => 'Ничего не найдено.',
  'clients.search_reset' => 'Сброс',

  'clients.action_settings' => 'Настройки',
  'clients.action_add_client' => 'Добавить клиента',
  'clients.action_personal_file' => 'Личное дело',
  'clients.action_edit' => 'Редактировать',
  'clients.action_block' => 'Заблокировать',
  'clients.action_unblock' => 'Разблокировать',
  'clients.action_reset_password' => 'Сброс пароля (на email)',

  'clients.col_client' => 'Клиент',
  'clients.col_phone_login' => 'Телефон (логин)',
  'clients.col_email' => 'Email',
  'clients.col_status' => 'Статус',
  'clients.col_created' => 'Создан',
  'clients.empty_list' => 'Клиентов нет.',
  'clients.dash' => '-',

  'clients.status_active' => 'Активен',
  'clients.status_blocked' => 'Заблокирован',

  'clients.modal_add_title' => 'Добавить клиента',
  'clients.modal_add_card_title' => 'Новый клиент',
  'clients.modal_add_hint' => 'Телефон - логин. Временный пароль будет сгенерирован при создании.',
  'clients.modal_update_title' => 'Редактировать клиента',
  'clients.modal_update_card_title' => 'Клиент #{id}',
  'clients.modal_update_hint' => 'Редактирование данных клиента.',

  'clients.modal_settings_title' => 'Настройки клиентов',
  'clients.modal_settings_card_title' => 'Настройки клиентов (тест)',
  'clients.modal_settings_hint' => 'Опасная зона. Только для тестового контура.',
  'clients.modal_settings_text' => 'Действие удалит все записи из clients. Заявки и данные других модулей не удаляются.',
  'clients.modal_settings_clear_button' => 'Очистить всех клиентов (тест)',
  'clients.modal_settings_confirm' => 'ТЕСТОВЫЙ РЕЖИМ: удалить ВСЕХ клиентов? Действие необратимо.',

  'clients.field_first_name_required' => 'Имя *',
  'clients.field_last_name' => 'Фамилия',
  'clients.field_middle_name' => 'Отчество',
  'clients.field_phone_login_required' => 'Номер телефона (логин) *',
  'clients.field_email' => 'Email',
  'clients.field_inn' => 'ИНН',
  'clients.field_birth_date' => 'Дата рождения',
  'clients.field_photo' => 'Фото',
  'clients.field_status' => 'Статус',
  'clients.photo_uploaded' => 'Фото загружено',

  'clients.btn_save' => 'Сохранить',
  'clients.btn_cancel' => 'Отмена',

  'clients.error_bad_id' => 'Некорректный ID',
  'clients.error_client_not_found' => 'Клиент не найден',
  'clients.error_forbidden' => 'Доступ запрещен',
  'clients.error_access_denied' => 'Доступ запрещен',

  'clients.flash_required_name_phone' => 'Имя и телефон обязательны',
  'clients.flash_duplicate_phone' => 'Клиент с таким телефоном уже существует (ID: {id})',
  'clients.flash_client_created_with_email' => 'Клиент создан. Временный пароль (пока без email-отправки): {password}',
  'clients.flash_client_created' => 'Клиент создан. Временный пароль: {password}',
  'clients.flash_create_error' => 'Ошибка создания клиента',

  'clients.flash_invalid_id' => 'Некорректный ID',
  'clients.flash_phone_used_by_other' => 'Телефон уже используется другим клиентом (ID: {id})',
  'clients.flash_client_updated' => 'Клиент обновлен',
  'clients.flash_update_error' => 'Ошибка обновления клиента',

  'clients.flash_client_not_found' => 'Клиент не найден',
  'clients.flash_client_status_updated' => 'Статус клиента: {status}',
  'clients.flash_status_change_error' => 'Ошибка смены статуса',

  'clients.flash_invalid_client' => 'Некорректный клиент',
  'clients.flash_no_email_for_reset' => 'У клиента нет email - сброс невозможен',
  'clients.flash_password_sent' => 'Новый пароль отправлен на email клиента',
  'clients.flash_password_mail_failed' => 'Письмо не отправилось. Временный пароль: {password}',
  'clients.flash_password_reset_error' => 'Ошибка сброса пароля клиента',

  'clients.flash_confirm_required' => 'Нужно подтверждение операции',
  'clients.flash_clear_done' => 'Тестовая очистка выполнена. Удалено клиентов: {count}',
  'clients.flash_clear_failed' => 'Не удалось очистить клиентов в тестовом режиме',

  'clients.email_subject_new_password' => 'CRM2026: новый пароль',
  'clients.email_body_new_password' => "Ваш новый временный пароль: {password}\n\nРекомендуем сменить пароль после входа.",

  'clients.client_fallback' => 'Клиент #{id}',
  'clients.inn_prefix' => 'ИНН',
  'clients.js_modal_title' => 'Модалка',
];
