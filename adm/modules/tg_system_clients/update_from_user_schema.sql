-- Миграция старой схемы tg_system_clients (user_id) -> новая схема (client_id).
-- Выполнять вручную, если модуль ранее уже создавался старым install.sql.

START TRANSACTION;

ALTER TABLE `tg_system_clients_user_events`
  RENAME TO `tg_system_clients_client_events`;

ALTER TABLE `tg_system_clients_client_events`
  CHANGE COLUMN `user_id` `client_id` INT NOT NULL;

ALTER TABLE `tg_system_clients_links`
  CHANGE COLUMN `user_id` `client_id` INT NOT NULL;

ALTER TABLE `tg_system_clients_links`
  DROP INDEX `uq_user_id`,
  ADD UNIQUE KEY `uq_client_id` (`client_id`);

ALTER TABLE `tg_system_clients_link_tokens`
  CHANGE COLUMN `user_id` `client_id` INT NOT NULL;

ALTER TABLE `tg_system_clients_link_tokens`
  DROP INDEX `idx_user_id`,
  ADD KEY `idx_client_id` (`client_id`);

ALTER TABLE `tg_system_clients_dispatch_log`
  CHANGE COLUMN `user_id` `client_id` INT NOT NULL DEFAULT 0;

ALTER TABLE `tg_system_clients_dispatch_log`
  DROP INDEX `idx_user_id`,
  ADD KEY `idx_client_id` (`client_id`);

COMMIT;