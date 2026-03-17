<?php
/**
 * FILE: /adm/modules/services/lang/ru.php
 * ROLE: Словарь модуля services (RU).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле services.
 *  - Используется через services_t('services.*') или t('services.*').
 */

declare(strict_types=1);

return [
  'services.page_title' => 'Услуги',
  'services.search_placeholder' => 'Поиск по категориям и услугам',
  'services.action_reset_filter' => 'Сбросить фильтры',
  'services.action_add_category' => 'Добавить категорию',
  'services.action_add_service' => 'Добавить услугу',

  'services.section_categories_title' => 'Категории',
  'services.section_categories_hint' => 'Группировка услуг (поиск общий)',
  'services.section_services_title' => 'Услуги',
  'services.section_services_hint' => 'Внешняя форма использует только активные услуги',

  'services.col_category' => 'Категория',
  'services.col_services_count' => 'Услуг',
  'services.col_service' => 'Услуга',
  'services.col_price' => 'Цена',
  'services.col_duration' => 'Длительность',
  'services.col_specialists' => 'Спецов',

  'services.action_edit_category' => 'Редактировать категорию',
  'services.action_edit_service' => 'Редактировать услугу',
  'services.action_delete' => 'Удалить',

  'services.confirm_delete_category_with_services' => 'Удалить категорию и все ее услуги?',
  'services.confirm_delete_service_forever' => 'Удалить услугу навсегда?',

  'services.empty_categories' => 'Категорий нет.',
  'services.empty_services' => 'Услуг нет.',
  'services.empty_services_in_category' => 'В этой категории нет услуг.',

  'services.suggest_type_category' => 'Категория',
  'services.suggest_type_service' => 'Услуга',

  'services.field_service_name' => 'Название услуги',
  'services.field_price' => 'Стоимость',
  'services.field_duration_min' => 'Длительность (мин)',
  'services.field_category' => 'Категория',
  'services.field_specialists' => 'Специалисты',

  'services.placeholder_category_name' => 'Название категории',
  'services.placeholder_service_name' => 'Название услуги',
  'services.placeholder_service_name_example' => 'Например: Стрижка',
  'services.placeholder_price' => 'Стоимость, ₽',
  'services.placeholder_duration_min' => 'Длительность, мин',
  'services.placeholder_specialist_search' => 'Поиск специалиста',

  'services.option_uncategorized' => '— Без категории —',
  'services.no_specialists' => 'Нет специалистов',

  'services.modal_default_title' => 'Модалка',
  'services.modal_category_add_title' => 'Новая категория',
  'services.modal_category_update_title' => 'Редактировать категорию',
  'services.modal_category_update_card_title' => 'Категория #{id}',
  'services.modal_category_update_hint' => 'Редактирование названия',
  'services.modal_service_add_title' => 'Новая услуга',
  'services.modal_service_update_title' => 'Редактировать услугу',
  'services.modal_service_update_card_title' => 'Услуга #{id}',
  'services.modal_service_update_hint' => 'Редактирование названия и связей',

  'services.btn_create' => 'Создать',
  'services.btn_save' => 'Сохранить',
  'services.btn_close' => 'Закрыть',

  'services.dash' => '—',
  'services.duration_suffix_min' => 'мин',

  'services.error_forbidden' => 'Доступ запрещен',
  'services.error_bad_id' => 'Некорректный id',
  'services.error_category_not_found' => 'Категория не найдена',
  'services.error_service_not_found' => 'Услуга не найдена',

  'services.flash_category_name_required' => 'Название категории обязательно',
  'services.flash_category_duplicate' => 'Категория с таким названием уже есть',
  'services.flash_category_created' => 'Категория создана',
  'services.flash_category_create_error' => 'Ошибка создания категории',
  'services.flash_category_invalid_id' => 'Некорректный id категории',
  'services.flash_category_not_found' => 'Категория не найдена',
  'services.flash_uncategorized_protected_delete' => 'Категория "Без категории" системная и не удаляется',
  'services.flash_category_deleted' => 'Категория удалена',
  'services.flash_category_delete_error' => 'Ошибка удаления категории',
  'services.flash_uncategorized_auto_managed' => 'Категория "Без категории" управляется автоматически',
  'services.flash_category_enabled' => 'Категория включена',
  'services.flash_category_disabled' => 'Категория отключена',
  'services.flash_category_toggle_error' => 'Ошибка переключения категории',
  'services.flash_category_invalid_or_empty' => 'Категория не найдена или имя пустое',
  'services.flash_category_updated' => 'Категория обновлена',
  'services.flash_category_update_error' => 'Ошибка обновления категории',

  'services.flash_service_name_required' => 'Название услуги обязательно',
  'services.flash_service_duplicate' => 'Услуга с таким названием уже есть',
  'services.flash_service_created' => 'Услуга создана',
  'services.flash_service_create_error' => 'Ошибка создания услуги',
  'services.flash_service_invalid_id' => 'Некорректный id услуги',
  'services.flash_service_not_found' => 'Услуга не найдена',
  'services.flash_service_invalid_or_empty' => 'Услуга не найдена или имя пустое',
  'services.flash_service_updated' => 'Услуга обновлена',
  'services.flash_service_update_error' => 'Ошибка обновления услуги',
  'services.flash_service_deleted' => 'Услуга удалена',
  'services.flash_service_delete_error' => 'Ошибка удаления услуги',
  'services.flash_service_category_disabled' => 'Категория отключена — сначала включите ее',
  'services.flash_service_enabled' => 'Услуга включена',
  'services.flash_service_disabled' => 'Услуга отключена',
  'services.flash_service_toggle_error' => 'Ошибка переключения услуги',
];