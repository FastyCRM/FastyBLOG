-- FILE: /adm/modules/channel_bridge/update.sql
-- ROLE: Incremental schema for direct Telegram webhook mode.

CREATE TABLE IF NOT EXISTS `channel_bridge_webhook_updates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `update_id` BIGINT UNSIGNED NOT NULL,
  `update_type` VARCHAR(32) NOT NULL DEFAULT '',
  `source_chat_id` VARCHAR(191) NOT NULL DEFAULT '',
  `source_message_id` VARCHAR(128) NOT NULL DEFAULT '',
  `media_group_id` VARCHAR(128) NOT NULL DEFAULT '',
  `message_date` DATETIME NULL DEFAULT NULL,
  `edit_date` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_update_id` (`update_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_source` (`source_chat_id`, `source_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_tg_posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_chat_id` VARCHAR(191) NOT NULL DEFAULT '',
  `source_message_id` VARCHAR(128) NOT NULL DEFAULT '',
  `media_group_id` VARCHAR(128) NOT NULL DEFAULT '',
  `message_type` VARCHAR(16) NOT NULL DEFAULT 'single',
  `message_text` MEDIUMTEXT NOT NULL,
  `tg_text_html` MEDIUMTEXT NOT NULL,
  `tg_public_post_url` VARCHAR(255) NOT NULL DEFAULT '',
  `tg_chat_username` VARCHAR(191) NOT NULL DEFAULT '',
  `message_date` DATETIME NULL DEFAULT NULL,
  `edit_date` DATETIME NULL DEFAULT NULL,
  `payload_raw` MEDIUMTEXT NOT NULL,
  `photo_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `photo_downloaded` INT UNSIGNED NOT NULL DEFAULT 0,
  `photo_failed` INT UNSIGNED NOT NULL DEFAULT 0,
  `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_source_message` (`source_chat_id`, `source_message_id`),
  KEY `idx_media_group` (`source_chat_id`, `media_group_id`),
  KEY `idx_message_type` (`message_type`),
  KEY `idx_last_seen_at` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_tg_post_photos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_chat_id` VARCHAR(191) NOT NULL DEFAULT '',
  `source_message_id` VARCHAR(128) NOT NULL DEFAULT '',
  `media_group_id` VARCHAR(128) NOT NULL DEFAULT '',
  `tg_file_id` VARCHAR(191) NOT NULL DEFAULT '',
  `local_path` VARCHAR(255) NOT NULL DEFAULT '',
  `download_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `download_error` VARCHAR(255) NOT NULL DEFAULT '',
  `sort` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_source_photo` (`source_chat_id`, `source_message_id`, `tg_file_id`),
  KEY `idx_media_group` (`source_chat_id`, `media_group_id`),
  KEY `idx_status` (`download_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_tg_albums` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_chat_id` VARCHAR(191) NOT NULL DEFAULT '',
  `media_group_id` VARCHAR(128) NOT NULL DEFAULT '',
  `dispatch_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `dispatch_token` CHAR(64) NOT NULL DEFAULT '',
  `revision` INT UNSIGNED NOT NULL DEFAULT 0,
  `dispatch_revision` INT UNSIGNED NOT NULL DEFAULT 0,
  `items_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `photos_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `photos_downloaded` INT UNSIGNED NOT NULL DEFAULT 0,
  `photos_failed` INT UNSIGNED NOT NULL DEFAULT 0,
  `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dispatch_started_at` DATETIME NULL DEFAULT NULL,
  `dispatched_at` DATETIME NULL DEFAULT NULL,
  `last_error` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_source_album` (`source_chat_id`, `media_group_id`),
  KEY `idx_dispatch_status` (`dispatch_status`),
  KEY `idx_last_seen_at` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_type` VARCHAR(32) NOT NULL DEFAULT '',
  `job_key` VARCHAR(255) NOT NULL DEFAULT '',
  `status` VARCHAR(16) NOT NULL DEFAULT 'new',
  `payload_json` MEDIUMTEXT NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_at` DATETIME NULL DEFAULT NULL,
  `locked_by` VARCHAR(64) NOT NULL DEFAULT '',
  `last_error` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_key` (`job_key`),
  KEY `idx_status_available` (`status`, `available_at`),
  KEY `idx_locked_at` (`locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_vk_cleanup_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `route_title` VARCHAR(190) NOT NULL DEFAULT '',
  `owner_id` VARCHAR(64) NOT NULL DEFAULT '',
  `link_substring` VARCHAR(255) NOT NULL DEFAULT '',
  `requested_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `scanned_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `pinned_skipped` INT UNSIGNED NOT NULL DEFAULT 0,
  `scan_offset` INT UNSIGNED NOT NULL DEFAULT 0,
  `wall_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'queued',
  `status_text` VARCHAR(255) NOT NULL DEFAULT '',
  `current_post_id` BIGINT NOT NULL DEFAULT 0,
  `current_sort` INT UNSIGNED NOT NULL DEFAULT 0,
  `started_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL DEFAULT NULL,
  `last_error` VARCHAR(255) NOT NULL DEFAULT '',
  `log_text` MEDIUMTEXT NOT NULL,
  `last_worker_spawn_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_route_status` (`route_id`, `status`, `id`),
  KEY `idx_status` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_vk_cleanup_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `post_id` BIGINT NOT NULL DEFAULT 0,
  `post_date` DATETIME NULL DEFAULT NULL,
  `sort` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `last_error` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_post` (`task_id`, `post_id`),
  KEY `idx_task_sort` (`task_id`, `sort`, `id`),
  KEY `idx_task_status` (`task_id`, `status`, `next_attempt_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @cb_has_route_blacklist_domains := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'channel_bridge_routes'
    AND COLUMN_NAME = 'blacklist_domains'
);
SET @cb_route_blacklist_sql := IF(
  @cb_has_route_blacklist_domains = 0,
  'ALTER TABLE `channel_bridge_routes` ADD COLUMN `blacklist_domains` TEXT NOT NULL AFTER `target_extra`',
  'SELECT 1'
);
PREPARE cb_route_blacklist_stmt FROM @cb_route_blacklist_sql;
EXECUTE cb_route_blacklist_stmt;
DEALLOCATE PREPARE cb_route_blacklist_stmt;

SET @cb_has_vk_cleanup_link_substring := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'channel_bridge_vk_cleanup_tasks'
    AND COLUMN_NAME = 'link_substring'
);
SET @cb_vk_cleanup_link_substring_sql := IF(
  @cb_has_vk_cleanup_link_substring = 0,
  'ALTER TABLE `channel_bridge_vk_cleanup_tasks` ADD COLUMN `link_substring` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `owner_id`',
  'SELECT 1'
);
PREPARE cb_vk_cleanup_link_substring_stmt FROM @cb_vk_cleanup_link_substring_sql;
EXECUTE cb_vk_cleanup_link_substring_stmt;
DEALLOCATE PREPARE cb_vk_cleanup_link_substring_stmt;

SET @cb_has_vk_cleanup_scan_offset := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'channel_bridge_vk_cleanup_tasks'
    AND COLUMN_NAME = 'scan_offset'
);
SET @cb_vk_cleanup_scan_offset_sql := IF(
  @cb_has_vk_cleanup_scan_offset = 0,
  'ALTER TABLE `channel_bridge_vk_cleanup_tasks` ADD COLUMN `scan_offset` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `pinned_skipped`',
  'SELECT 1'
);
PREPARE cb_vk_cleanup_scan_offset_stmt FROM @cb_vk_cleanup_scan_offset_sql;
EXECUTE cb_vk_cleanup_scan_offset_stmt;
DEALLOCATE PREPARE cb_vk_cleanup_scan_offset_stmt;

SET @cb_has_vk_cleanup_wall_total := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'channel_bridge_vk_cleanup_tasks'
    AND COLUMN_NAME = 'wall_total'
);
SET @cb_vk_cleanup_wall_total_sql := IF(
  @cb_has_vk_cleanup_wall_total = 0,
  'ALTER TABLE `channel_bridge_vk_cleanup_tasks` ADD COLUMN `wall_total` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `scan_offset`',
  'SELECT 1'
);
PREPARE cb_vk_cleanup_wall_total_stmt FROM @cb_vk_cleanup_wall_total_sql;
EXECUTE cb_vk_cleanup_wall_total_stmt;
DEALLOCATE PREPARE cb_vk_cleanup_wall_total_stmt;

SET @cb_has_vk_cleanup_status_text := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'channel_bridge_vk_cleanup_tasks'
    AND COLUMN_NAME = 'status_text'
);
SET @cb_vk_cleanup_status_text_sql := IF(
  @cb_has_vk_cleanup_status_text = 0,
  'ALTER TABLE `channel_bridge_vk_cleanup_tasks` ADD COLUMN `status_text` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `status`',
  'SELECT 1'
);
PREPARE cb_vk_cleanup_status_text_stmt FROM @cb_vk_cleanup_status_text_sql;
EXECUTE cb_vk_cleanup_status_text_stmt;
DEALLOCATE PREPARE cb_vk_cleanup_status_text_stmt;

SET @cb_has_vk_cleanup_current_post_id := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'channel_bridge_vk_cleanup_tasks'
    AND COLUMN_NAME = 'current_post_id'
);
SET @cb_vk_cleanup_current_post_id_sql := IF(
  @cb_has_vk_cleanup_current_post_id = 0,
  'ALTER TABLE `channel_bridge_vk_cleanup_tasks` ADD COLUMN `current_post_id` BIGINT NOT NULL DEFAULT 0 AFTER `status_text`',
  'SELECT 1'
);
PREPARE cb_vk_cleanup_current_post_id_stmt FROM @cb_vk_cleanup_current_post_id_sql;
EXECUTE cb_vk_cleanup_current_post_id_stmt;
DEALLOCATE PREPARE cb_vk_cleanup_current_post_id_stmt;

SET @cb_has_vk_cleanup_current_sort := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'channel_bridge_vk_cleanup_tasks'
    AND COLUMN_NAME = 'current_sort'
);
SET @cb_vk_cleanup_current_sort_sql := IF(
  @cb_has_vk_cleanup_current_sort = 0,
  'ALTER TABLE `channel_bridge_vk_cleanup_tasks` ADD COLUMN `current_sort` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `current_post_id`',
  'SELECT 1'
);
PREPARE cb_vk_cleanup_current_sort_stmt FROM @cb_vk_cleanup_current_sort_sql;
EXECUTE cb_vk_cleanup_current_sort_stmt;
DEALLOCATE PREPARE cb_vk_cleanup_current_sort_stmt;

SET @cb_has_vk_cleanup_log_text := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'channel_bridge_vk_cleanup_tasks'
    AND COLUMN_NAME = 'log_text'
);
SET @cb_vk_cleanup_log_text_sql := IF(
  @cb_has_vk_cleanup_log_text = 0,
  'ALTER TABLE `channel_bridge_vk_cleanup_tasks` ADD COLUMN `log_text` MEDIUMTEXT NOT NULL AFTER `last_error`',
  'SELECT 1'
);
PREPARE cb_vk_cleanup_log_text_stmt FROM @cb_vk_cleanup_log_text_sql;
EXECUTE cb_vk_cleanup_log_text_stmt;
DEALLOCATE PREPARE cb_vk_cleanup_log_text_stmt;
