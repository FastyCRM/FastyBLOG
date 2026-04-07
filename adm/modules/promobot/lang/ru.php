<?php
/**
 * FILE: /adm/modules/promobot/lang/ru.php
 * ROLE: Словарь RU для модуля promobot.
 */

return [
  'promobot.page_title' => 'Промобот',
  'promobot.page_hint' => 'Бот отвечает на входящие сообщения по ключевым словам и выдаёт промокоды.',

  'promobot.install_title' => 'Требуется установка БД',
  'promobot.install_hint' => 'Примените SQL схемы модуля или нажмите кнопку установки.',
  'promobot.action_install_db' => 'Создать БД',

  'promobot.section_switch' => 'Выбор бота',
  'promobot.field_current_bot' => 'Текущий бот',
  'promobot.no_bots' => 'Боты не найдены',
  'promobot.action_select' => 'Открыть',
  'promobot.action_add_bot' => 'Добавить бота',
  'promobot.action_log_disable' => 'Отключить логирование',
  'promobot.action_log_enable' => 'Включить логирование',

  'promobot.section_bots' => 'Боты',
  'promobot.col_name' => 'Название',
  'promobot.col_platform' => 'Платформа',
  'promobot.col_status' => 'Статус',
  'promobot.col_webhook' => 'Webhook',
  'promobot.status_on' => 'ON',
  'promobot.status_off' => 'OFF',

  'promobot.section_channels' => 'Каналы и чаты',
  'promobot.channels_hint' => 'Привяжите чат через /bind CODE. Без привязки ответы не отправляются.',
  'promobot.action_bind_code' => 'Сгенерировать код привязки',
  'promobot.col_chat' => 'Чат',
  'promobot.col_chat_type' => 'Тип',
  'promobot.no_channels' => 'Привязок пока нет',

  'promobot.section_promos' => 'Промокоды',
  'promobot.promos_hint' => 'Ключевые слова — через запятую. Бот ищет подстроки во входящих сообщениях.',
  'promobot.action_add_promo' => 'Добавить промокод',
  'promobot.search_promos_label' => 'Поиск по ключевым словам',
  'promobot.search_promos_placeholder' => 'Введите ключевое слово',
  'promobot.search_promos_empty' => 'По запросу ничего не найдено',
  'promobot.col_keywords' => 'Ключевые слова',
  'promobot.col_response' => 'Ответ',
  'promobot.no_promos' => 'Промокоды не добавлены',

  'promobot.section_users' => 'Пользователи',
  'promobot.field_user' => 'Пользователь',
  'promobot.action_attach' => 'Назначить',
  'promobot.col_user' => 'Пользователь',
  'promobot.col_roles' => 'Роли',
  'promobot.no_users' => 'Пользователи не назначены',

  'promobot.action_edit' => 'Редактировать',
  'promobot.action_disable' => 'Выключить',
  'promobot.action_enable' => 'Включить',
  'promobot.action_webhook_set' => 'Подключить webhook',
  'promobot.action_delete' => 'Удалить',
  'promobot.action_unbind' => 'Отвязать',
  'promobot.action_detach' => 'Снять',
  'promobot.action_save' => 'Сохранить',

  'promobot.confirm_bot_delete' => 'Удалить бота и все связанные данные?',
  'promobot.confirm_channel_unbind' => 'Отвязать этот чат?',
  'promobot.confirm_promo_delete' => 'Удалить промокод?',
  'promobot.confirm_user_detach' => 'Снять пользователя с бота?',

  'promobot.platform_tg' => 'Telegram',
  'promobot.platform_max' => 'MAX',

  'promobot.field_bot_name' => 'Название бота',
  'promobot.field_platform' => 'Платформа',
  'promobot.field_enabled' => 'Включен',
  'promobot.field_bot_token' => 'Bot token',
  'promobot.field_webhook_secret' => 'Webhook secret',
  'promobot.field_webhook_url' => 'Webhook URL',
  'promobot.field_max_api_key' => 'MAX API key',
  'promobot.field_max_base_url' => 'MAX base URL',
  'promobot.field_max_send_path' => 'MAX send path',

  'promobot.field_keywords' => 'Ключевые слова',
  'promobot.field_keywords_placeholder' => 'магнит, магнит косметик, магнит у дома',
  'promobot.field_response_text' => 'Текст ответа',
  'promobot.field_active' => 'Активен',

  'promobot.modal_bot_add_title' => 'Добавить бота',
  'promobot.modal_bot_update_title' => 'Редактировать бота',
  'promobot.modal_promo_add_title' => 'Добавить промокод',
  'promobot.modal_promo_update_title' => 'Редактировать промокод',
  'promobot.bot_add_hint' => 'После создания откройте бота и заполните токены/ключи.',

  'promobot.flash_access_denied' => 'Недостаточно прав.',
  'promobot.flash_install_tables_empty' => 'Список таблиц пуст.',
  'promobot.flash_install_no_need' => 'Таблицы уже существуют.',
  'promobot.flash_install_done' => 'Установка завершена. Создано: {created}, уже было: {existing}.',
  'promobot.flash_install_error' => 'Ошибка установки: {error}',
  'promobot.error_db_name' => 'Не удалось определить имя БД.',
  'promobot.error_install_sql_missing' => 'Файл install.sql не найден.',
  'promobot.error_install_sql_empty' => 'Файл install.sql пуст.',
  'promobot.error_install_missing_create' => 'Не найдены CREATE выражения для таблиц',
  'promobot.error_schema_missing' => 'Таблицы модуля не установлены.',

  'promobot.flash_log_enabled' => 'Логирование включено.',
  'promobot.flash_log_disabled' => 'Логирование выключено.',

  'promobot.flash_bot_not_found' => 'Бот не найден.',
  'promobot.flash_bot_name_required' => 'Введите название бота.',
  'promobot.flash_bot_added' => 'Бот добавлен.',
  'promobot.flash_bot_updated' => 'Бот обновлен.',
  'promobot.flash_bot_enabled' => 'Бот включен.',
  'promobot.flash_bot_disabled' => 'Бот выключен.',
  'promobot.flash_bot_deleted' => 'Бот удален.',
  'promobot.flash_bot_delete_error' => 'Ошибка удаления бота: {error}',
  'promobot.flash_bot_platform_mismatch' => 'Неверная платформа бота.',
  'promobot.flash_bot_token_empty' => 'Заполните bot token.',
  'promobot.flash_webhook_set_ok' => 'Webhook подключен.',
  'promobot.flash_webhook_set_fail' => 'Не удалось подключить webhook.',

  'promobot.flash_bind_code' => 'Код привязки: {code}. Действует до {expires_at}.',
  'promobot.flash_bind_code_fail' => 'Не удалось сгенерировать код привязки.',

  'promobot.flash_channel_not_found' => 'Чат не найден.',
  'promobot.flash_channel_enabled' => 'Чат включен.',
  'promobot.flash_channel_disabled' => 'Чат выключен.',
  'promobot.flash_channel_unbound' => 'Чат отвязан.',

  'promobot.flash_promo_not_found' => 'Промокод не найден.',
  'promobot.flash_promo_required' => 'Заполните ключевые слова и текст ответа.',
  'promobot.flash_promo_added' => 'Промокод добавлен.',
  'promobot.flash_promo_updated' => 'Промокод обновлен.',
  'promobot.flash_promo_enabled' => 'Промокод включен.',
  'promobot.flash_promo_disabled' => 'Промокод выключен.',
  'promobot.flash_promo_deleted' => 'Промокод удален.',

  'promobot.flash_user_attach_fail' => 'Не удалось назначить пользователя.',
  'promobot.flash_user_attached' => 'Пользователь назначен.',
  'promobot.flash_user_detach_fail' => 'Не удалось снять пользователя.',
  'promobot.flash_user_detached' => 'Пользователь снят.',

  'promobot.bind_ok' => 'Чат привязан.',
  'promobot.bind_fail' => 'Не удалось привязать чат.',

  'promobot.dash' => '—',
];
