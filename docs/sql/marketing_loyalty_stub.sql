-- Заготовка БД для будущего модуля акций/скидок/лояльности.
-- Выполняется вручную админом/разработчиком.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `marketing_programs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `type` ENUM('promotion','discount','loyalty') NOT NULL DEFAULT 'promotion',
  `name` VARCHAR(190) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `priority` INT NOT NULL DEFAULT 100,
  `starts_at` DATETIME NULL DEFAULT NULL,
  `ends_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_type_active` (`type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `marketing_program_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id` INT UNSIGNED NOT NULL,
  `rule_code` VARCHAR(64) NOT NULL,
  `discount_type` ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `min_order_total` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `service_ids_json` TEXT NOT NULL,
  `specialist_ids_json` TEXT NOT NULL,
  `conditions_json` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_program_rule` (`program_id`, `rule_code`),
  KEY `idx_program_id` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `marketing_client_loyalty` (
  `client_id` INT NOT NULL,
  `tier_code` VARCHAR(64) NOT NULL DEFAULT '',
  `points_balance` INT NOT NULL DEFAULT 0,
  `total_spent` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `last_event_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`),
  KEY `idx_tier_code` (`tier_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `marketing_client_programs` (
  `client_id` INT NOT NULL,
  `program_id` INT UNSIGNED NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `joined_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`, `program_id`),
  KEY `idx_program_id` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;