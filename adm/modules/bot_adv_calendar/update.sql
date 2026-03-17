START TRANSACTION;

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
