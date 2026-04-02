-- FILE: /adm/modules/channel_bridge/update.sql
-- ROLE: Дозарез схемы модуля channel_bridge для защиты Telegram webhook.

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
