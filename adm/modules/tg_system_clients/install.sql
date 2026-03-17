-- Модуль: tg_system_clients
-- Установка выполняется вручную (SQL запускает админ/разработчик).

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `tg_system_clients_settings` (
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

CREATE TABLE IF NOT EXISTS `tg_system_clients_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_code` VARCHAR(64) NOT NULL,
  `title` VARCHAR(120) NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `global_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 100,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_code` (`event_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tg_system_clients_client_events` (
  `client_id` INT NOT NULL,
  `event_code` VARCHAR(64) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`, `event_code`),
  KEY `idx_event_code` (`event_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tg_system_clients_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT NOT NULL,
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
  UNIQUE KEY `uq_client_id` (`client_id`),
  UNIQUE KEY `uq_chat_id` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tg_system_clients_link_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  `used_chat_id` VARCHAR(64) NOT NULL DEFAULT '',
  `created_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tg_system_clients_dispatch_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_code` VARCHAR(64) NOT NULL,
  `client_id` INT NOT NULL DEFAULT 0,
  `chat_id` VARCHAR(64) NOT NULL DEFAULT '',
  `message_text` TEXT NOT NULL,
  `send_status` VARCHAR(16) NOT NULL DEFAULT 'queued',
  `error_text` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_event_code` (`event_code`),
  KEY `idx_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tg_system_clients_settings`
(`id`, `enabled`, `bot_token`, `webhook_secret`, `webhook_url`, `default_parse_mode`, `token_ttl_minutes`, `retention_days`)
VALUES (1, 0, '', '', '', 'HTML', 15, 7)
ON DUPLICATE KEY UPDATE
  `id` = `id`;

INSERT INTO `tg_system_clients_events`
(`event_code`, `title`, `description`, `global_enabled`, `sort_order`)
VALUES
  ('general', 'Общие уведомления клиенту', 'Служебные и информационные сообщения по работе CRM.', 1, 10),
  ('requests_client_accepted', 'Заявка принята', 'Заявка принята в работу: услуги, сумма, мастер, скидка.', 1, 20),
  ('requests_client_confirmed', 'Заявка подтверждена', 'Подтверждение заявки и времени визита.', 1, 30),
  ('requests_client_changed', 'Заявка изменена', 'Изменения по времени, специалисту или составу услуг.', 1, 40),
  ('requests_client_reminder_60m', 'Напоминание за 60 минут', 'Напоминание клиенту за час до визита.', 1, 50),
  ('requests_client_thanks', 'Благодарность после визита', 'Спасибо за визит после завершения заявки.', 1, 60),
  ('client_promotions', 'Акции и скидки', 'Рассылки по акциям, скидкам и программам лояльности.', 1, 70)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`);

INSERT INTO `modules` (`code`, `name`, `icon`, `sort`, `enabled`, `menu`, `roles`, `has_settings`)
VALUES (
  'tg_system_clients',
  'TG клиенты',
  'bi bi-telegram',
  951,
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