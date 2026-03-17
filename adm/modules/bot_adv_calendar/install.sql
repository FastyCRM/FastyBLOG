-- Модуль: bot_adv_calendar
-- Установка выполняется вручную (без runtime DDL)

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `bot_adv_calendar_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `bot_token` VARCHAR(255) NOT NULL DEFAULT '',
  `webhook_secret` VARCHAR(255) NOT NULL DEFAULT '',
  `webhook_url` VARCHAR(255) NOT NULL DEFAULT '',
  `default_parse_mode` VARCHAR(20) NOT NULL DEFAULT 'HTML',
  `token_ttl_minutes` INT NOT NULL DEFAULT 15,
  `retention_days` INT NOT NULL DEFAULT 7,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_adv_calendar_user_access` (
  `user_id` INT NOT NULL,
  `created_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_adv_calendar_user_options` (
  `user_id` INT NOT NULL,
  `booking_mode` ENUM('fixed','range') NOT NULL DEFAULT 'fixed',
  `slot_interval_minutes` INT NOT NULL DEFAULT 60,
  `work_start` TIME NOT NULL DEFAULT '09:00:00',
  `work_end` TIME NOT NULL DEFAULT '18:00:00',
  `dayoff_mask` CHAR(7) NOT NULL DEFAULT '0000011',
  `updated_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_adv_calendar_user_windows` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `weekday` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `window_type` ENUM('fixed','range') NOT NULL DEFAULT 'fixed',
  `time_from` TIME NOT NULL,
  `time_to` TIME NULL DEFAULT NULL,
  `price` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 100,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_weekday` (`weekday`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_adv_calendar_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type` ENUM('user','client') NOT NULL,
  `actor_id` INT NOT NULL,
  `chat_id` VARCHAR(64) NOT NULL,
  `chat_type` VARCHAR(32) NOT NULL DEFAULT '',
  `username` VARCHAR(128) NOT NULL DEFAULT '',
  `first_name` VARCHAR(120) NOT NULL DEFAULT '',
  `last_name` VARCHAR(120) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `linked_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_actor` (`actor_type`, `actor_id`),
  UNIQUE KEY `uq_chat_id` (`chat_id`),
  KEY `idx_actor_type` (`actor_type`),
  KEY `idx_actor_id` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_adv_calendar_link_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type` ENUM('user','client') NOT NULL,
  `actor_id` INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  `used_chat_id` VARCHAR(64) NOT NULL DEFAULT '',
  `created_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_actor` (`actor_type`, `actor_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_adv_calendar_client_onboarding` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_id` VARCHAR(64) NOT NULL,
  `step` ENUM('await_name','await_phone') NOT NULL DEFAULT 'await_name',
  `client_name` VARCHAR(160) NOT NULL DEFAULT '',
  `phone_raw` VARCHAR(32) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_id` (`chat_id`),
  KEY `idx_step` (`step`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_adv_calendar_dispatch_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_code` VARCHAR(64) NOT NULL,
  `actor_type` ENUM('user','client') NOT NULL,
  `actor_id` INT NOT NULL DEFAULT 0,
  `chat_id` VARCHAR(64) NOT NULL DEFAULT '',
  `payload_text` TEXT NOT NULL,
  `send_status` VARCHAR(16) NOT NULL DEFAULT 'queued',
  `error_text` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_event_code` (`event_code`),
  KEY `idx_actor` (`actor_type`, `actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `bot_adv_calendar_settings`
(`id`, `enabled`, `bot_token`, `webhook_secret`, `webhook_url`, `default_parse_mode`, `token_ttl_minutes`, `retention_days`)
VALUES (1, 0, '', '', '', 'HTML', 15, 7)
ON DUPLICATE KEY UPDATE
  `id` = `id`;

INSERT INTO `modules` (`code`, `name`, `icon`, `sort`, `enabled`, `menu`, `roles`, `has_settings`)
VALUES (
  'bot_adv_calendar',
  'Bot Adv Calendar',
  'bi bi-calendar2-week',
  952,
  1,
  1,
  '["admin","manager","user"]',
  1
)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `icon` = VALUES(`icon`),
  `sort` = VALUES(`sort`),
  `menu` = VALUES(`menu`),
  `roles` = VALUES(`roles`),
  `has_settings` = VALUES(`has_settings`);

COMMIT;
