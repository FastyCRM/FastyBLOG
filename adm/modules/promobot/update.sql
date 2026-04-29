-- Модуль: promobot
-- Обновление схемы для переиспользования списков промокодов между ботами
-- MySQL 8.0.24: без ADD COLUMN IF NOT EXISTS

START TRANSACTION;

SET @db_name := DATABASE();

SET @has_promo_source_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'promobot_bots'
    AND COLUMN_NAME = 'promo_source_bot_id'
);

SET @sql_add_promo_source_col := IF(
  @has_promo_source_col = 0,
  'ALTER TABLE `promobot_bots` ADD COLUMN `promo_source_bot_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `max_send_path`',
  'SELECT 1'
);

PREPARE stmt_add_col FROM @sql_add_promo_source_col;
EXECUTE stmt_add_col;
DEALLOCATE PREPARE stmt_add_col;

SET @has_promo_source_idx := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'promobot_bots'
    AND INDEX_NAME = 'idx_promo_source_bot_id'
);

SET @sql_add_promo_source_idx := IF(
  @has_promo_source_idx = 0,
  'ALTER TABLE `promobot_bots` ADD KEY `idx_promo_source_bot_id` (`promo_source_bot_id`)',
  'SELECT 1'
);

PREPARE stmt_add_idx FROM @sql_add_promo_source_idx;
EXECUTE stmt_add_idx;
DEALLOCATE PREPARE stmt_add_idx;

COMMIT;
