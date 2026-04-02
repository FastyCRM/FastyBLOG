-- FILE: /adm/modules/channel_bridge/install.sql
-- ROLE: Каноничная SQL-схема модуля channel_bridge.
-- RULES:
-- 1) Таблицы из этого файла перечислены в CHANNEL_BRIDGE_TABLES.
-- 2) do=install_db создаёт только отсутствующие таблицы из CREATE TABLE.

CREATE TABLE IF NOT EXISTS `channel_bridge_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,

  `tg_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `tg_bot_token` VARCHAR(255) NOT NULL DEFAULT '',
  `tg_webhook_secret` VARCHAR(255) NOT NULL DEFAULT '',
  `tg_parse_mode` VARCHAR(20) NOT NULL DEFAULT 'HTML',

  `vk_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `vk_group_token` VARCHAR(255) NOT NULL DEFAULT '',
  `vk_owner_id` VARCHAR(64) NOT NULL DEFAULT '',
  `vk_api_version` VARCHAR(16) NOT NULL DEFAULT '5.199',

  `max_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `max_api_key` VARCHAR(255) NOT NULL DEFAULT '',
  `max_base_url` VARCHAR(255) NOT NULL DEFAULT 'https://platform-api.max.ru',
  `max_send_path` VARCHAR(255) NOT NULL DEFAULT '/messages',

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_routes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(190) NOT NULL DEFAULT '',
  `source_platform` VARCHAR(20) NOT NULL,
  `source_chat_id` VARCHAR(191) NOT NULL,
  `target_platform` VARCHAR(20) NOT NULL,
  `target_chat_id` VARCHAR(191) NOT NULL,
  `target_extra` TEXT NOT NULL,
  `blacklist_domains` TEXT NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_route` (`source_platform`, `source_chat_id`, `target_platform`, `target_chat_id`),
  KEY `idx_source` (`source_platform`, `source_chat_id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_inbox` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_platform` VARCHAR(20) NOT NULL,
  `source_chat_id` VARCHAR(191) NOT NULL,
  `source_message_id` VARCHAR(128) NULL DEFAULT NULL,
  `message_text` MEDIUMTEXT NOT NULL,
  `payload_raw` MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_source_message` (`source_platform`, `source_chat_id`, `source_message_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_dispatch_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `source_platform` VARCHAR(20) NOT NULL DEFAULT '',
  `source_chat_id` VARCHAR(191) NOT NULL DEFAULT '',
  `source_message_id` VARCHAR(128) NOT NULL DEFAULT '',
  `target_platform` VARCHAR(20) NOT NULL DEFAULT '',
  `target_chat_id` VARCHAR(191) NOT NULL DEFAULT '',
  `message_text` MEDIUMTEXT NOT NULL,
  `send_status` VARCHAR(16) NOT NULL DEFAULT 'failed',
  `error_text` VARCHAR(255) NOT NULL DEFAULT '',
  `response_raw` MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_route_id` (`route_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_bind_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `bind_side` VARCHAR(16) NOT NULL DEFAULT '',
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  `used_chat_id` VARCHAR(191) NOT NULL DEFAULT '',
  `used_chat_type` VARCHAR(32) NOT NULL DEFAULT '',
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_route_side` (`route_id`, `bind_side`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `channel_bridge_link_suffix_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain_root` VARCHAR(190) NOT NULL DEFAULT '',
  `suffix_text` TEXT NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 100,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_domain_root` (`domain_root`),
  KEY `idx_enabled_sort` (`enabled`, `sort`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

INSERT INTO `channel_bridge_settings`
(`id`, `enabled`, `tg_enabled`, `tg_bot_token`, `tg_webhook_secret`, `tg_parse_mode`, `vk_enabled`, `vk_group_token`, `vk_owner_id`, `vk_api_version`, `max_enabled`, `max_api_key`, `max_base_url`, `max_send_path`)
VALUES
(1, 0, 1, '', '', 'HTML', 0, '', '', '5.199', 0, '', 'https://platform-api.max.ru', '/messages')
ON DUPLICATE KEY UPDATE
  `id` = `id`;

INSERT INTO `modules` (`code`, `name`, `icon`, `sort`, `enabled`, `menu`, `roles`, `has_settings`)
VALUES (
  'channel_bridge',
  'Кросспостинг',
  'bi bi-share',
  952,
  1,
  1,
  '["admin","manager"]',
  1
)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `icon` = VALUES(`icon`),
  `sort` = VALUES(`sort`),
  `menu` = VALUES(`menu`),
  `roles` = VALUES(`roles`),
  `has_settings` = VALUES(`has_settings`);
