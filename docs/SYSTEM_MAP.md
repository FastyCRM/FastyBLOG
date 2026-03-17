# SYSTEM_MAP

## 1) Дерево папок (актуально)
```
/
├─ core/        # ядро (auth, db, csrf, audit, acl, response, mailer, internal_api)
├─ adm/         # админка (layout + модули)
│  ├─ index.php     # единственная точка входа в админку
│  ├─ view/         # layout + глобальные assets (css/js/img)
│  └─ modules/      # модули (auth, dashboard, clients, requests, personal_file, services, calendar, oauth_tokens, ym_link_bot, tg_system_*, channel_bridge …)
├─ site/        # публичная часть (landing + внешняя заявка)
├─ docs/        # архитектурные документы
├─ logs/        # audit-fallback.log и прочие логи
├─ storage/     # служебные файлы/временные данные
├─ scripts/     # вспомогательные скрипты
├─ index.php    # корневой dispatcher (adm/site)
└─ crm2026.sql  # дамп БД
```

## 2) Entry points (входные точки)
- `/index.php` — корневой dispatcher: `/adm` → админка, иначе → сайт.
- `/adm/index.php` — единственная точка входа в админку.
- `/site/index.php` — landing + внешняя форма заявки.
- `/core/internal_api.php` — внутренний API (модули ↔ site).

## 3) Админ‑роутинг (канон)
```
/adm/index.php
  -> /core/bootstrap.php
  -> /adm/view/index.php
     -> /adm/modules/<module>/<module>.php (VIEW)
     -> /adm/modules/<module>/assets/php/main.php (router do)
        -> /adm/modules/<module>/assets/php/<module>_<do>.php
```

## 4) Модульная структура (канон)
```
/adm/modules/<module_code>/
  <module_code>.php       # VIEW (UI only)
  settings.php            # параметры/константы (без логики)
  README.md               # обязательная документация модуля
  /assets/
    /php/
      main.php            # приемник do
      <module>_<do>.php   # action handler
      *_lib.php           # вспомогательные функции
    /js/
      main.js
    /css/
      main.css
```

## 5) Внутренний API (контур)
- Точка входа: `/core/internal_api.php?m=<module>&do=api_*`.
- Только `do=api_*`.
- Вся логика остаётся внутри модуля, internal_api лишь маршрутизирует.

## Telegram endpoints
- `/core/telegram_webhook_system_users.php` — webhook бота сотрудников.
- `/core/telegram_webhook_system_clients.php` — webhook клиентского бота.
- `/core/internal_api.php?m=tg_system_users&do=api_send`
- `/core/internal_api.php?m=tg_system_clients&do=api_send`
