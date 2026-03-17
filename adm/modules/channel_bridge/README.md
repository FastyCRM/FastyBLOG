# channel_bridge

Модуль маршрутизации сообщений между каналами/чатами (TG/VK/MAX) с журналированием отправок.

## Точки входа
- UI: `/adm/index.php?m=channel_bridge`
- Настройки: `/adm/index.php?m=channel_bridge&do=modal_settings`
- Ручной install: `/adm/index.php?m=channel_bridge&do=install_db`
- API ingest: `/adm/index.php?m=channel_bridge&do=api_ingest`
- TG webhook: `/adm/index.php?m=channel_bridge&do=api_tg_webhook`

## Разрешенные do
- `modal_settings`, `settings_update`, `install_db`
- `modal_route_add`, `route_add`, `modal_route_update`, `route_update`, `route_toggle`, `route_delete`, `route_test`, `route_bind_code`
- `max_probe` (диагностика MAX API + список чатов бота)
- `api_ingest`, `api_tg_webhook`

## Файлы
- VIEW: `adm/modules/channel_bridge/channel_bridge.php`
- Router: `adm/modules/channel_bridge/assets/php/main.php`
- SQL schema: `adm/modules/channel_bridge/install.sql`

## БД
Схема ставится вручную через `install.sql` или через `do=install_db` с проверкой наличия таблиц.
