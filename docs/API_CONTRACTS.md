# API_CONTRACTS

Документ описывает **внутренний API** (`/core/internal_api.php`) и ключевые do‑эндпоинты, которые разрешено дергать из site/других модулей.

## 1) Базовые правила
- Входная точка: `/core/internal_api.php?m=<module>&do=api_*`.
- Если в `core/config.php` задан `internal_api.key`, передать:
  - заголовок `X-Internal-Api-Key`, либо
  - query `?key=...`.
- Ответы: JSON через `json_ok()` / `json_err()`.

## 2) requests → api_services
**GET** `/core/internal_api.php?m=requests&do=api_services`

**Ответ:**
```json
{
  "ok": true,
  "use_specialists": 1,
  "use_time_slots": 0,
  "services": [ {"id":1,"name":"..."} ],
  "spec_map": { "<service_id>": [ {"id":7,"name":"..."} ] }
}
```

Назначение:
- вернуть активные услуги;
- карту «услуга → специалисты»;
- флаги режимов.

## 3) requests → api_request_add
**POST** `/core/internal_api.php?m=requests&do=api_request_add`

**Параметры (POST):**
- `csrf` (обязателен)
- `client_name` (обязателен)
- `client_phone` (обязателен, нормализуется до 7XXXXXXXXXX)
- `client_email` (опционально)
- `service_id` (обязателен, если включены специалисты)
- `specialist_user_id` (обязателен, если включены специалисты)
- `visit_date` (YYYY-MM-DD, опционально)
- `visit_time` (HH:MM, опционально)
- `consent` = 1 (обязателен)

**Ответ (ok):**
```json
{ "ok": true, "id": 123, "message": "..." }
```
**Ответ (error):**
```json
{ "ok": false, "error": "..." }
```

Примечания:
- Если клиента нет — создаётся в `clients`.
- При наличии даты/времени формируется `slot_key`.
- Логирование через `audit_log()`.

## 4) calendar → api_specialists
**GET** `/core/internal_api.php?m=calendar&do=api_specialists`

**Ответ:**
```json
{ "ok": true, "items": [ {"id":7,"name":"..."} ] }
```

## 5) calendar → api_slots_day
**GET** `/core/internal_api.php?m=calendar&do=api_slots_day&specialist_id=<id>&date=YYYY-MM-DD`

**Ответ:**
```json
{
  "ok": true,
  "specialist_id": 7,
  "date": "2026-02-03",
  "slots": ["09:00","09:30"],
  "has_schedule": true,
  "slot_min": 30,
  "slot_duration": 30,
  "time_start": "09:00:00",
  "time_end": "18:00:00",
  "lead_minutes": 60
}
```

## 6) calendar → api_slots_nearest
**GET** `/core/internal_api.php?m=calendar&do=api_slots_nearest&specialist_id=<id>&date=YYYY-MM-DD`

**Ответ:**
```json
{
  "ok": true,
  "specialist_id": 7,
  "date": "2026-02-05",
  "slots": ["10:00","10:30"],
  "has_schedule": true
}
```

## Telegram helpers (core)
- `sendSystemTG(string $message, string $eventCode = 'general', array $options = [])`
  - модуль-получатель: `tg_system_users`
  - options: `user_ids` (array<int>), `parse_mode`.
- `sendClientTG(string $message, string $eventCode = 'general', array $options = [])`
  - модуль-получатель: `tg_system_clients`
  - options: `client_ids` (array<int>), `parse_mode`.

## tg_system_users -> api_send
**POST/GET** `/core/internal_api.php?m=tg_system_users&do=api_send`
- params: `message`, `event_code` (optional), `user_ids` (optional)
- auth: session (admin/manager) or `X-Internal-Api-Key`.

## tg_system_clients -> api_send
**POST/GET** `/core/internal_api.php?m=tg_system_clients&do=api_send`
- params: `message`, `event_code` (optional), `client_ids` (optional)
- auth: session (admin/manager) or `X-Internal-Api-Key`.

## bot_adv_calendar
- UI module: `/adm/index.php?m=bot_adv_calendar`
- Webhook endpoint: `/adm/modules/bot_adv_calendar/webhook.php`
- Internal API endpoints (`/core/internal_api.php`) на текущем этапе не публикуются.
- Telegram callback/commands:
  - `/start <6-digit-code>` или сообщение `<6-digit-code>` для привязки;
  - inline-кнопка `Календарь рекламы` (`callback_data=calendar_open`).

## Telegram webhooks
- `/core/telegram_webhook_system_users.php`
- `/core/telegram_webhook_system_clients.php`
