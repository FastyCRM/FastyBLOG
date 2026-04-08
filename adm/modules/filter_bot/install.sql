-- Модуль: filter_bot
-- Установка выполняется вручную через do=install_db или прямое применение файла.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `filter_bot_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `log_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `tg_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `tg_bot_token` VARCHAR(255) NOT NULL DEFAULT '',
  `tg_webhook_secret` VARCHAR(255) NOT NULL DEFAULT '',
  `tg_allow_private` TINYINT(1) NOT NULL DEFAULT 0,
  `tg_skip_admins` TINYINT(1) NOT NULL DEFAULT 1,
  `max_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `max_api_key` VARCHAR(255) NOT NULL DEFAULT '',
  `max_base_url` VARCHAR(255) NOT NULL DEFAULT 'https://platform-api.max.ru',
  `max_skip_admins` TINYINT(1) NOT NULL DEFAULT 1,
  `warn_badword_text` VARCHAR(255) NOT NULL DEFAULT '{mention}, пожалуйста, без мата.',
  `warn_link_text` VARCHAR(255) NOT NULL DEFAULT 'Данная ссылка запрещена в этом чате, {mention}.',
  `max_warn_badword_text` VARCHAR(255) NOT NULL DEFAULT 'Сообщение скрыто модерацией: запрещённая лексика.',
  `max_warn_link_text` VARCHAR(255) NOT NULL DEFAULT 'Сообщение скрыто модерацией: запрещённая ссылка.',
  `badwords_list` MEDIUMTEXT NOT NULL,
  `allowed_domains_list` MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `filter_bot_channels` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform` ENUM('tg','max') NOT NULL,
  `chat_id` VARCHAR(64) NOT NULL,
  `chat_title` VARCHAR(190) NOT NULL DEFAULT '',
  `chat_type` VARCHAR(32) NOT NULL DEFAULT '',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `last_seen_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_platform_chat` (`platform`, `chat_id`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `filter_bot_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform` ENUM('tg','max') NOT NULL,
  `chat_id` VARCHAR(64) NOT NULL DEFAULT '',
  `chat_title` VARCHAR(190) NOT NULL DEFAULT '',
  `chat_type` VARCHAR(32) NOT NULL DEFAULT '',
  `message_id` VARCHAR(64) NOT NULL DEFAULT '',
  `from_id` VARCHAR(64) NOT NULL DEFAULT '',
  `from_name` VARCHAR(190) NOT NULL DEFAULT '',
  `message_text` TEXT NOT NULL,
  `rule_code` VARCHAR(32) NOT NULL DEFAULT '',
  `action_code` VARCHAR(32) NOT NULL DEFAULT '',
  `status` VARCHAR(16) NOT NULL DEFAULT 'new',
  `error_text` VARCHAR(255) NOT NULL DEFAULT '',
  `raw_meta` MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_platform_chat` (`platform`, `chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `filter_bot_updates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform` ENUM('tg','max') NOT NULL,
  `update_key` VARCHAR(191) NOT NULL,
  `chat_id` VARCHAR(64) NOT NULL DEFAULT '',
  `message_id` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_update_key` (`update_key`),
  KEY `idx_platform_chat` (`platform`, `chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `filter_bot_settings`
(
  `id`,
  `enabled`,
  `log_enabled`,
  `tg_enabled`,
  `tg_bot_token`,
  `tg_webhook_secret`,
  `tg_allow_private`,
  `tg_skip_admins`,
  `max_enabled`,
  `max_api_key`,
  `max_base_url`,
  `max_skip_admins`,
  `warn_badword_text`,
  `warn_link_text`,
  `max_warn_badword_text`,
  `max_warn_link_text`,
  `badwords_list`,
  `allowed_domains_list`
)
VALUES
(
  1,
  0,
  1,
  0,
  '',
  '',
  0,
  1,
  0,
  '',
  'https://platform-api.max.ru',
  1,
  '{mention}, пожалуйста, без мата.',
  'Данная ссылка запрещена в этом чате, {mention}.',
  'Сообщение скрыто модерацией: запрещённая лексика.',
  'Сообщение скрыто модерацией: запрещённая ссылка.',
  '',
  ''
)
ON DUPLICATE KEY UPDATE
  `id` = `id`;

INSERT INTO `modules` (`code`, `name`, `icon`, `sort`, `enabled`, `menu`, `roles`, `has_settings`)
VALUES (
  'filter_bot',
  'Фильтр TG / MAX',
  'bi bi-shield-lock',
  959,
  1,
  1,
  '["admin","manager"]',
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
