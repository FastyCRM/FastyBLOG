<?php
/**
 * FILE: /core/config.db.local.example.php
 * ROLE: Пример локального override профилей БД.
 * USAGE:
 * 1) Скопируйте файл в /core/config.db.local.php
 * 2) Укажите свои значения только для нужных профилей
 * 3) config.php автоматически подхватит этот файл
 *
 * Важно:
 * - Этот файл-пример не используется напрямую.
 * - Рабочий файл: /core/config.db.local.php
 */

declare(strict_types=1);

return [
  'openserver' => [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'crm2026blog',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
  ],
  'mamp' => [
    'host' => 'localhost',
    'port' => 8889,
    'name' => 'crm2026blog',
    'user' => 'root',
    'pass' => 'root',
    'charset' => 'utf8mb4',
  ],
  'hosting' => [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'your_hosting_db_name',
    'user' => 'your_hosting_db_user',
    'pass' => 'your_hosting_db_password',
    'charset' => 'utf8mb4',
  ],
];

