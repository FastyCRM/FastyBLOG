# calendar

Модуль календаря/расписания (view-only на основе заявок).

## Точки входа
- UI: `/adm/index.php?m=calendar`
- Внутренние API:
  - `/adm/index.php?m=calendar&do=api_specialists`
  - `/adm/index.php?m=calendar&do=api_slots_day`
  - `/adm/index.php?m=calendar&do=api_slots_nearest`

## Разрешенные do
- `modal_settings`
- `modal_manager_settings`
- `settings_update`
- `api_specialists`
- `api_slots_day`
- `api_slots_nearest`

## Файлы
- VIEW: `adm/modules/calendar/calendar.php`
- Router: `adm/modules/calendar/assets/php/main.php`
- API router: `adm/modules/calendar/assets/php/calendar_api_main.php`

## БД
Отдельного `install.sql` у модуля нет. Используются таблицы общей схемы (`requests`, `requests_settings`, `calendar_user_settings`, `specialist_schedule`).
