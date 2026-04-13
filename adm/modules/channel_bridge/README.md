# channel_bridge

Message routing module for Telegram, VK and MAX with direct webhook delivery.

## Entry points
- UI: `/adm/index.php?m=channel_bridge`
- Settings: `/adm/index.php?m=channel_bridge&do=modal_settings`
- Manual install: `/adm/index.php?m=channel_bridge&do=install_db`
- API ingest: `/adm/index.php?m=channel_bridge&do=api_ingest`
- TG webhook: `/adm/index.php?m=channel_bridge&do=api_tg_webhook`

## Allowed do
- `modal_settings`, `settings_update`, `install_db`
- `modal_route_add`, `route_add`, `modal_route_update`, `route_update`, `route_toggle`, `route_delete`, `route_test`, `route_bind_code`
- `max_probe`
- `api_ingest`, `api_tg_webhook`

## Runtime flow
- single Telegram post: immediate dispatch inside webhook
- Telegram media_group: file-backed local assembly with `flock`, 100 ms polling step, 800 ms max wait, 500 ms quiet window
- duplicate updates: blocked by `channel_bridge_webhook_updates`
- old media_group buffers: cleaned during webhook requests, no scheduler required

## Files
- view: `adm/modules/channel_bridge/channel_bridge.php`
- router: `adm/modules/channel_bridge/assets/php/main.php`
- library: `adm/modules/channel_bridge/assets/php/channel_bridge_lib.php`
- schema: `adm/modules/channel_bridge/install.sql`
