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
