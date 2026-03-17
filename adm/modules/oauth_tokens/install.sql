-- Модуль: oauth_tokens
-- Установка выполняется вручную (без runtime DDL).

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `oauth_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(190) NOT NULL,
  `client_id` VARCHAR(255) NOT NULL,
  `client_secret` VARCHAR(255) NOT NULL,
  `access_token` TEXT NULL,
  `token_received_at` DATETIME NULL DEFAULT NULL,
  `created_by` INT NOT NULL DEFAULT 0,
  `updated_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`),
  KEY `idx_token_received_at` (`token_received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `oauth_token_users` (
  `oauth_token_id` INT UNSIGNED NOT NULL,
  `user_id` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`oauth_token_id`),
  UNIQUE KEY `uq_user_id` (`user_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `modules` (`code`, `name`, `icon`, `sort`, `enabled`, `menu`, `roles`, `has_settings`)
VALUES (
  'oauth_tokens',
  'OAuth токены',
  'bi bi-key',
  940,
  1,
  1,
  '["admin","user"]',
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
