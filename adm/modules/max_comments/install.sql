-- Модуль: max_comments
-- Установка выполняется вручную (без runtime DDL).

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `max_comments_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `button_text` VARCHAR(64) NOT NULL DEFAULT 'Комментарии',
  `max_api_key` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @__mc_has_max_api_key := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'max_comments_settings'
    AND column_name = 'max_api_key'
);
SET @__mc_sql := IF(
  @__mc_has_max_api_key = 0,
  'ALTER TABLE `max_comments_settings` ADD COLUMN `max_api_key` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `button_text`',
  'SELECT 1'
);
PREPARE __mc_stmt FROM @__mc_sql;
EXECUTE __mc_stmt;
DEALLOCATE PREPARE __mc_stmt;

CREATE TABLE IF NOT EXISTS `max_comments_channels` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_id` VARCHAR(191) NOT NULL,
  `title` VARCHAR(190) NOT NULL DEFAULT '',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_id` (`chat_id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `max_comments_processed` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_id` VARCHAR(191) NOT NULL,
  `message_id` VARCHAR(191) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'new',
  `error_text` VARCHAR(255) NOT NULL DEFAULT '',
  `raw_update` MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_message` (`chat_id`, `message_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `max_comments_settings`
(`id`, `enabled`, `button_text`, `max_api_key`)
VALUES
(1, 0, 'Комментарии', '')
ON DUPLICATE KEY UPDATE
  `id` = `id`;

INSERT INTO `modules` (`code`, `name`, `icon`, `sort`, `enabled`, `menu`, `roles`, `has_settings`)
VALUES (
  'max_comments',
  'MAX Комментарии',
  'bi bi-chat-left-text',
  953,
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

COMMIT;
