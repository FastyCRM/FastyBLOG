# CALENDAR (модуль календаря)

## 1) Назначение
- Отображение заявок как расписания.
- Календарь **не создаёт** заявок — это делает модуль requests.
- Поддерживает интервальный режим (если он включён в requests).

## 2) Источник данных
- **Заявки**: таблица `requests` (поле `visit_at`).
- **График специалистов**: `specialist_schedule`.

## 3) Режимы
### Режим специалиста (user)
- Видит **только свои** заявки.
- Окно по умолчанию: 4 дня.
- Колонки — дни.

### Режим менеджера (manager)
- Видит выбранных специалистов за **1 день**.
- Колонки — специалисты.
- Доступен admin/manager.

## 4) Персональные настройки
- Таблица `calendar_user_settings`:
  - `mode` = `user` или `manager`
  - `manager_spec_ids` = список выбранных специалистов
- Настройки **персональные**, не глобальные.

## 5) Дата просмотра
- Выбор даты через мини‑календарь слева.
- Дата хранится в URL (`?date=YYYY-MM-DD`) и **не пишется в БД**.
- Есть кнопка «Сброс» к текущему дню.

## 6) Интервалы
- Если `requests_settings.use_time_slots=1`, календарь строит разметку по `slot_min`.
- Линии сетки строятся на основе `specialist_schedule`.

## 7) Внутренний API
- `GET /core/internal_api.php?m=calendar&do=api_specialists`
- `GET /core/internal_api.php?m=calendar&do=api_slots_day&specialist_id=<id>&date=YYYY-MM-DD`
- `GET /core/internal_api.php?m=calendar&do=api_slots_nearest&specialist_id=<id>&date=YYYY-MM-DD`
