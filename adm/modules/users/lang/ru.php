<?php
/**
 * FILE: /adm/modules/users/lang/ru.php
 * ROLE: Словарь модуля users (RU).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле users.
 *  - Используется через users_t('users.*') или t('users.*').
 */

declare(strict_types=1);

return [
  'users.page_title' => 'Пользователи',
  'users.col_user' => 'Пользователь',
  'users.col_phone' => 'Телефон',
  'users.col_roles' => 'Роли',
  'users.col_status' => 'Статус',
  'users.action_add_user' => 'Добавить пользователя',
  'users.action_edit' => 'Редактировать',
  'users.action_block' => 'Заблокировать',
  'users.action_unblock' => 'Разблокировать',
  'users.action_reset_password' => 'Сброс пароля',
  'users.empty_list' => 'Пользователей нет.',
  'users.dash' => '—',

  'users.status_active' => 'active',
  'users.status_blocked' => 'blocked',

  'users.modal_add_title' => 'Добавить пользователя',
  'users.modal_add_card_title' => 'Новый пользователь',
  'users.modal_add_hint' => 'Manager создаёт только роль user. Admin может назначить роли.',
  'users.modal_update_title' => 'Редактировать пользователя',
  'users.modal_user_title' => 'Пользователь #{id}',
  'users.modal_update_hint_admin' => 'Admin может менять роли.',
  'users.modal_update_hint_manager' => 'Manager не управляет ролями.',

  'users.field_name' => 'Имя',
  'users.field_phone' => 'Телефон',
  'users.field_email' => 'Email',
  'users.field_email_for_reset' => 'Email (для сброса пароля)',
  'users.field_status' => 'Статус',
  'users.field_ui_theme' => 'Тема UI',
  'users.field_roles' => 'Роли',

  'users.work_mode_title' => 'Рабочий режим (календарь)',
  'users.work_mode_hint' => 'Настройка для календаря специалиста.',
  'users.table_day' => 'День',
  'users.table_day_off' => 'Выходной',
  'users.table_from' => 'С',
  'users.table_to' => 'До',
  'users.table_break_from' => 'Обед с',
  'users.table_break_to' => 'Обед до',
  'users.day_off' => 'выходной',
  'users.weekday_1' => 'Пн',
  'users.weekday_2' => 'Вт',
  'users.weekday_3' => 'Ср',
  'users.weekday_4' => 'Чт',
  'users.weekday_5' => 'Пт',
  'users.weekday_6' => 'Сб',
  'users.weekday_7' => 'Вс',

  'users.btn_save' => 'Сохранить',
  'users.btn_cancel' => 'Отмена',
  'users.btn_close' => 'Закрыть',

  'users.error_bad_id' => 'Некорректный ID',
  'users.error_user_not_found' => 'Пользователь не найден',
  'users.flash_invalid_user' => 'Некорректный пользователь',
  'users.flash_required_fields' => 'Имя, телефон и email обязательны',
  'users.flash_role_assign_failed' => 'Не удалось назначить роль пользователю',
  'users.flash_user_created' => 'Пользователь создан. Временный пароль: {password}',
  'users.flash_user_updated' => 'Пользователь обновлён',
  'users.flash_user_create_error' => 'Ошибка создания пользователя',
  'users.flash_user_update_error' => 'Ошибка обновления пользователя',
  'users.flash_self_block_forbidden' => 'Нельзя блокировать самого себя',
  'users.flash_status_updated' => 'Статус обновлён: {status}',
  'users.flash_status_change_error' => 'Ошибка изменения статуса',
  'users.flash_self_reset_blocked' => 'Свой пароль меняется отдельно (позже). Здесь — только для других пользователей.',
  'users.flash_no_email_for_reset' => 'У пользователя нет email — сброс невозможен',
  'users.flash_password_sent' => 'Новый пароль отправлен на email',
  'users.flash_password_mail_failed' => 'Письмо не отправилось. Временный пароль: {password}',
  'users.flash_password_reset_error' => 'Ошибка сброса пароля',

  'users.email_subject_new_password' => 'CRM2026: новый пароль',
  'users.email_body_new_password' => "Ваш новый временный пароль: {password}\n\nРекомендуем сменить пароль после входа.",
];
