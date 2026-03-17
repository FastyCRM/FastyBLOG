<?php
/**
 * FILE: /core/db.php
 * ROLE: Подключение к базе данных (PDO)
 * CONNECTIONS:
 *  - /core/config.php (db.*)
 *
 * NOTES:
 *  - В этом файле ТОЛЬКО работа с PDO.
 *  - Никакой бизнес-логики.
 *  - Исключения НЕ подавляются — пусть решает вызывающая сторона.
 */

declare(strict_types=1);

/**
 * db()
 * Возвращает PDO-соединение (lazy singleton).
 *
 * @throws PDOException при ошибке подключения
 */
function db(): PDO {
  static $pdo = null;

  if ($pdo instanceof PDO) {
    return $pdo;
  }

  $cfg = app_config();
  $db  = (array)($cfg['db'] ?? []);

  $host    = (string)($db['host'] ?? 'localhost');
  $port    = (int)($db['port'] ?? 3306);
  $name    = (string)($db['name'] ?? '');
  $user    = (string)($db['user'] ?? '');
  $pass    = (string)($db['pass'] ?? '');
  $charset = (string)($db['charset'] ?? 'utf8mb4');

  $dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $host,
    $port,
    $name,
    $charset
  );

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);

  return $pdo;
}
