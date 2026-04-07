-- Модуль: promobot
-- Установка выполняется вручную (без runtime DDL)

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `promobot_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `log_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `promobot_bots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL DEFAULT '',
  `platform` ENUM('tg','max') NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `bot_token` VARCHAR(255) NOT NULL DEFAULT '',
  `webhook_secret` VARCHAR(255) NOT NULL DEFAULT '',
  `webhook_url` VARCHAR(255) NOT NULL DEFAULT '',
  `max_api_key` VARCHAR(255) NOT NULL DEFAULT '',
  `max_base_url` VARCHAR(255) NOT NULL DEFAULT 'https://platform-api.max.ru',
  `max_send_path` VARCHAR(255) NOT NULL DEFAULT '/messages',
  `created_by` INT NOT NULL DEFAULT 0,
  `updated_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform` (`platform`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `promobot_channels` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id` INT UNSIGNED NOT NULL,
  `platform` ENUM('tg','max') NOT NULL,
  `chat_id` VARCHAR(64) NOT NULL,
  `chat_title` VARCHAR(190) NOT NULL DEFAULT '',
  `chat_type` VARCHAR(32) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `linked_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_chat` (`bot_id`, `platform`, `chat_id`),
  KEY `idx_bot` (`bot_id`),
  KEY `idx_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `promobot_promos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id` INT UNSIGNED NOT NULL,
  `keywords` VARCHAR(500) NOT NULL DEFAULT '',
  `response_text` TEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NOT NULL DEFAULT 0,
  `updated_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bot` (`bot_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `promobot_user_access` (
  `user_id` INT NOT NULL,
  `bot_id` INT UNSIGNED NOT NULL,
  `created_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `bot_id`),
  KEY `idx_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `promobot_bind_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id` INT UNSIGNED NOT NULL,
  `platform` ENUM('tg','max') NOT NULL,
  `code` CHAR(6) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  `used_chat_id` VARCHAR(64) NOT NULL DEFAULT '',
  `created_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_bot` (`bot_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `promobot_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id` INT UNSIGNED NOT NULL,
  `platform` ENUM('tg','max') NOT NULL,
  `chat_id` VARCHAR(64) NOT NULL DEFAULT '',
  `message_id` VARCHAR(64) NOT NULL DEFAULT '',
  `message_text` TEXT NOT NULL,
  `matched_promo_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `response_text` TEXT NOT NULL,
  `send_status` VARCHAR(16) NOT NULL DEFAULT 'queued',
  `error_text` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_bot` (`bot_id`),
  KEY `idx_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `promobot_settings`
(`id`, `log_enabled`)
VALUES (1, 1)
ON DUPLICATE KEY UPDATE
  `id` = `id`;

INSERT INTO `modules` (`code`, `name`, `icon`, `sort`, `enabled`, `menu`, `roles`, `has_settings`)
VALUES (
  'promobot',
  'Промобот',
  'bi bi-ticket-perforated',
  958,
  1,
  1,
  '["admin","manager","user"]',
  0
)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `icon` = VALUES(`icon`),
  `sort` = VALUES(`sort`),
  `menu` = VALUES(`menu`),
  `roles` = VALUES(`roles`),
  `has_settings` = VALUES(`has_settings`);

COMMIT;
