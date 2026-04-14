# channel_bridge

Message routing module for Telegram, VK and MAX with direct webhook delivery.

## Entry points
- UI: `/adm/index.php?m=channel_bridge`
- Settings: `/adm/index.php?m=channel_bridge&do=modal_settings`
- Manual install: `/adm/index.php?m=channel_bridge&do=install_db`
- API ingest: `/adm/index.php?m=channel_bridge&do=api_ingest`
- TG webhook: `/adm/index.php?m=channel_bridge&do=api_tg_webhook`
- TG finalize: `/core/channel_bridge_tg_finalize.php`

## Allowed do
- `modal_settings`, `settings_update`, `install_db`
- `modal_route_add`, `route_add`, `modal_route_update`, `route_update`, `route_toggle`, `route_delete`, `route_test`, `route_bind_code`
- `max_probe`
- `api_ingest`, `api_tg_webhook`, `api_tg_finalize`

## Runtime flow
- single Telegram post: immediate dispatch inside webhook
- Telegram media_group: webhook stores each item in DB, responds immediately, then triggers separate finalize request
- duplicate updates: blocked by `channel_bridge_webhook_updates`
- album finalize: separate internal request claims the DB-backed album and dispatches after quiet window

## Files
- view: `adm/modules/channel_bridge/channel_bridge.php`
- router: `adm/modules/channel_bridge/assets/php/main.php`
- library: `adm/modules/channel_bridge/assets/php/channel_bridge_lib.php`
- schema: `adm/modules/channel_bridge/install.sql`
