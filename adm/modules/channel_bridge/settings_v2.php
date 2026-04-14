<?php
/**
 * FILE: /adm/modules/channel_bridge/settings_v2.php
 * VERSION: settings include rollover to bypass stale opcode cache.
 * ROLE: Module constants, tables, allow-list and sync media_group timings.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;
if (defined('CHANNEL_BRIDGE_MODULE_CODE')) return;

const CHANNEL_BRIDGE_MODULE_CODE = 'channel_bridge';

const CHANNEL_BRIDGE_TABLE_SETTINGS = 'channel_bridge_settings';
const CHANNEL_BRIDGE_TABLE_ROUTES = 'channel_bridge_routes';
const CHANNEL_BRIDGE_TABLE_INBOX = 'channel_bridge_inbox';
const CHANNEL_BRIDGE_TABLE_DISPATCH_LOG = 'channel_bridge_dispatch_log';
const CHANNEL_BRIDGE_TABLE_BIND_TOKENS = 'channel_bridge_bind_tokens';
const CHANNEL_BRIDGE_TABLE_LINK_SUFFIX_RULES = 'channel_bridge_link_suffix_rules';
const CHANNEL_BRIDGE_TABLE_WEBHOOK_UPDATES = 'channel_bridge_webhook_updates';
const CHANNEL_BRIDGE_TABLE_TG_POSTS = 'channel_bridge_tg_posts';
const CHANNEL_BRIDGE_TABLE_TG_POST_PHOTOS = 'channel_bridge_tg_post_photos';
const CHANNEL_BRIDGE_TABLE_TG_ALBUMS = 'channel_bridge_tg_albums';
const CHANNEL_BRIDGE_TABLE_JOBS = 'channel_bridge_jobs';

const CHANNEL_BRIDGE_MEDIA_GROUP_WAIT_MAX_MS = 15000;
const CHANNEL_BRIDGE_MEDIA_GROUP_WAIT_STEP_MS = 100;
const CHANNEL_BRIDGE_MEDIA_GROUP_QUIET_MS = 4000;
const CHANNEL_BRIDGE_MEDIA_GROUP_MIN_AGE_MS = 8000;
const CHANNEL_BRIDGE_MEDIA_GROUP_TTL_SECONDS = 600;
const CHANNEL_BRIDGE_MEDIA_GROUP_MIN_ITEMS = 8;
const CHANNEL_BRIDGE_MEDIA_GROUP_DISPATCH_STALE_SECONDS = 30;
const CHANNEL_BRIDGE_JOBS_ALBUM_QUIET_MS = 1000;
const CHANNEL_BRIDGE_JOBS_LOCK_STALE_SECONDS = 60;
const CHANNEL_BRIDGE_JOBS_MAX_ATTEMPTS = 4;
const CHANNEL_BRIDGE_JOBS_RETRY_DELAYS_SECONDS = [2, 5, 15, 60];
const CHANNEL_BRIDGE_WEBHOOK_QUEUE_ONLY = false;

const CHANNEL_BRIDGE_JOB_STATUS_NEW = 'new';
const CHANNEL_BRIDGE_JOB_STATUS_PROCESSING = 'processing';
const CHANNEL_BRIDGE_JOB_STATUS_DONE = 'done';
const CHANNEL_BRIDGE_JOB_STATUS_FAILED = 'failed';

const CHANNEL_BRIDGE_JOB_TYPE_SINGLE_POST = 'single_post';
const CHANNEL_BRIDGE_JOB_TYPE_ALBUM_FINALIZE = 'album_finalize';
const CHANNEL_BRIDGE_JOB_TYPE_ALBUM_PUBLISH = 'album_publish';
const CHANNEL_BRIDGE_JOB_TYPE_RETRY_PUBLISH = 'retry_publish';

const CHANNEL_BRIDGE_REQUIRED_TABLES = [
  CHANNEL_BRIDGE_TABLE_SETTINGS,
  CHANNEL_BRIDGE_TABLE_ROUTES,
  CHANNEL_BRIDGE_TABLE_INBOX,
  CHANNEL_BRIDGE_TABLE_DISPATCH_LOG,
  CHANNEL_BRIDGE_TABLE_BIND_TOKENS,
  CHANNEL_BRIDGE_TABLE_LINK_SUFFIX_RULES,
  CHANNEL_BRIDGE_TABLE_WEBHOOK_UPDATES,
];

const CHANNEL_BRIDGE_TABLES = [
  CHANNEL_BRIDGE_TABLE_SETTINGS,
  CHANNEL_BRIDGE_TABLE_ROUTES,
  CHANNEL_BRIDGE_TABLE_INBOX,
  CHANNEL_BRIDGE_TABLE_DISPATCH_LOG,
  CHANNEL_BRIDGE_TABLE_BIND_TOKENS,
  CHANNEL_BRIDGE_TABLE_LINK_SUFFIX_RULES,
  CHANNEL_BRIDGE_TABLE_WEBHOOK_UPDATES,
  CHANNEL_BRIDGE_TABLE_TG_POSTS,
  CHANNEL_BRIDGE_TABLE_TG_POST_PHOTOS,
  CHANNEL_BRIDGE_TABLE_TG_ALBUMS,
  CHANNEL_BRIDGE_TABLE_JOBS,
];

const CHANNEL_BRIDGE_ALLOWED_DO = [
  'modal_settings',
  'settings_update',
  'webhook_refresh',
  'install_db',
  'modal_route_add',
  'route_add',
  'modal_route_update',
  'route_update',
  'route_toggle',
  'route_delete',
  'route_test',
  'route_bind_code',
  'tg_probe',
  'max_probe',
  'api_ingest',
  'api_tg_webhook',
  'api_tg_finalize',
  'jobs_reset',
  'worker',
];

const CHANNEL_BRIDGE_SOURCE_TG = 'tg';

const CHANNEL_BRIDGE_TARGET_TG = 'tg';
const CHANNEL_BRIDGE_TARGET_VK = 'vk';
const CHANNEL_BRIDGE_TARGET_MAX = 'max';

const CHANNEL_BRIDGE_BIND_SIDE_SOURCE = 'source';
const CHANNEL_BRIDGE_BIND_SIDE_TARGET = 'target';

const CHANNEL_BRIDGE_BIND_CODE_TTL_MINUTES = 30;
const CHANNEL_BRIDGE_TG_UPDATE_MAX_AGE_SECONDS = 86400;
