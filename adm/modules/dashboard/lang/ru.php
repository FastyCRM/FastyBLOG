<?php
/**
 * FILE: /adm/modules/dashboard/lang/ru.php
 * ROLE: Словарь модуля dashboard (RU).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле dashboard.
 *  - Используется через t('dashboard.*').
 */

declare(strict_types=1);

return [
  'dashboard.profile_label_name' => 'Имя',
  'dashboard.profile_label_phone' => 'Телефон',
  'dashboard.profile_label_email' => 'Почта',
  'dashboard.profile_email_empty' => 'не указана',
  'dashboard.profile_label_theme' => 'Тема',

  'dashboard.notifications_aria' => 'Оповещения',
  'dashboard.notifications_title' => 'Непросмотренные оповещения',
  'dashboard.notifications_hint_stub' => 'Заглушка до подключения реального источника',
  'dashboard.notifications_empty' => 'Новых уведомлений нет',
  'dashboard.notification_fallback_title' => 'Уведомление',

  'dashboard.profile_settings_aria' => 'Настройки профиля',
  'dashboard.system_card_fallback_title' => 'Блок',
  'dashboard.system_card_fallback_action' => 'Открыть',

  'dashboard.recent_done_title' => 'Последние 4 выполненные заявки',
  'dashboard.requests_all' => 'Все заявки',
  'dashboard.table_client' => 'Клиент',
  'dashboard.table_service' => 'Услуга',
  'dashboard.table_date' => 'Дата',
  'dashboard.table_amount' => 'Сумма',
  'dashboard.recent_done_empty' => 'Выполненные заявки не найдены.',

  'dashboard.salary_title' => 'Расчёт ЗП',
  'dashboard.salary_hint_period' => '{percent}% от выполненных за последние {days} дней',
  'dashboard.salary_done_count' => 'Выполнено заявок:',
  'dashboard.salary_done_sum' => 'Сумма выполненных:',
  'dashboard.salary_piecework' => 'Сдельная оплата:',
  'dashboard.salary_piecework_on' => 'включена',
  'dashboard.salary_piecework_off' => 'выключена',

  'dashboard.modal_profile_edit_title' => 'Редактирование профиля',
  'dashboard.modal_profile_edit_hint' => 'Данные обновляются для текущего пользователя.',
  'dashboard.field_last_name' => 'Фамилия',
  'dashboard.field_name' => 'Имя',
  'dashboard.field_middle_name' => 'Отчество',
  'dashboard.field_phone' => 'Телефон',
  'dashboard.field_email' => 'Email',
  'dashboard.field_theme' => 'Тема',

  'dashboard.work_time_title' => 'Рабочее время',
  'dashboard.work_time_hint' => 'Те же настройки, что в модуле users (manager/admin).',
  'dashboard.work_lead_minutes' => 'Перерыв между приёмами (мин)',
  'dashboard.table_day' => 'День',
  'dashboard.table_day_off' => 'Выходной',
  'dashboard.table_from' => 'С',
  'dashboard.table_to' => 'До',
  'dashboard.table_break_from' => 'Перерыв с',
  'dashboard.table_break_to' => 'Перерыв до',
  'dashboard.day_off' => 'выходной',

  'dashboard.tg_title' => 'Telegram: системные уведомления',
  'dashboard.tg_hint' => 'Персональные on/off для текущего пользователя.',
  'dashboard.tg_events_empty' => 'События пока не настроены.',
  'dashboard.tg_globally_disabled' => '(глобально отключено)',

  'dashboard.save' => 'Сохранить',
  'dashboard.close' => 'Закрыть',
  'dashboard.modal_settings_title' => 'Настройки профиля',

  'dashboard.flash_user_not_defined' => 'Пользователь не определён',
  'dashboard.flash_profile_required' => 'Имя, телефон и email обязательны',
  'dashboard.flash_profile_saved' => 'Профиль сохранён',
  'dashboard.flash_profile_save_error' => 'Ошибка сохранения профиля',

  'dashboard.weekday_1' => 'Пн',
  'dashboard.weekday_2' => 'Вт',
  'dashboard.weekday_3' => 'Ср',
  'dashboard.weekday_4' => 'Чт',
  'dashboard.weekday_5' => 'Пт',
  'dashboard.weekday_6' => 'Сб',
  'dashboard.weekday_7' => 'Вс',

  'dashboard.user_not_defined' => 'Пользователь не определён',
  'dashboard.user_with_id' => 'Пользователь #{id}',

  'dashboard.stub_notify_title_1' => 'Новая версия CRM2026',
  'dashboard.stub_notify_text_1' => 'Скоро появится реальная лента системных уведомлений.',
  'dashboard.stub_notify_time_1' => 'сегодня',
  'dashboard.stub_notify_title_2' => 'Плановые работы',
  'dashboard.stub_notify_text_2' => 'Заглушка: сервисные уведомления будут здесь.',
  'dashboard.stub_notify_time_2' => 'вчера',
  'dashboard.stub_notify_title_3' => 'Напоминание',
  'dashboard.stub_notify_text_3' => 'Проверьте статусы заявок перед закрытием смены.',
  'dashboard.stub_notify_time_3' => '2 дня назад',

  'dashboard.stub_system_title_1' => 'Системные уведомления',
  'dashboard.stub_system_text_1' => 'Лента инфраструктурных событий. Сейчас работает как заглушка.',
  'dashboard.stub_system_action_1' => 'Открыть ленту',
  'dashboard.stub_system_title_2' => 'Канал с разработчиком',
  'dashboard.stub_system_text_2' => 'Канал связи будет подключён в следующем релизе (пока заглушка).',
  'dashboard.stub_system_action_2' => 'Открыть канал',
  'dashboard.stub_system_title_3' => 'Скидки и акции',
  'dashboard.stub_system_text_3' => 'Промо-блок подключён как заглушка до интеграции маркетинга.',
  'dashboard.stub_system_action_3' => 'Смотреть акции',
];
