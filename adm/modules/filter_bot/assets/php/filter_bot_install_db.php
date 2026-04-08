<?php
/**
 * FILE: /adm/modules/filter_bot/assets/php/filter_bot_install_db.php
 * ROLE: Ручное создание отсутствующих таблиц filter_bot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

if (!function_exists('filter_bot_install_db_split_sql')) {
  function filter_bot_install_db_split_sql(string $sql): array
  {
    $list = [];
    $buf = '';
    $len = strlen($sql);
    $quote = '';

    for ($i = 0; $i < $len; $i++) {
      $ch = $sql[$i];
      $prev = ($i > 0) ? $sql[$i - 1] : '';

      if ($quote !== '') {
        $buf .= $ch;
        if ($ch === $quote && $prev !== '\\') $quote = '';
        continue;
      }

      if ($ch === '\'' || $ch === '"' || $ch === '`') {
        $quote = $ch;
        $buf .= $ch;
        continue;
      }

      if ($ch === ';') {
        $stmt = trim($buf);
        if ($stmt !== '') $list[] = $stmt;
        $buf = '';
        continue;
      }

      $buf .= $ch;
    }

    $tail = trim($buf);
    if ($tail !== '') $list[] = $tail;

    return $list;
  }

  function filter_bot_install_db_statement_table(string $statement): string
  {
    if (!preg_match('~^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[a-zA-Z0-9_]+`?\.)?`?([a-zA-Z0-9_]+)`?\s*\(~iu', $statement, $m)) {
      return '';
    }
    return trim((string)($m[1] ?? ''));
  }

  function filter_bot_install_db_collect_create_map(string $sql): array
  {
    $map = [];
    foreach (filter_bot_install_db_split_sql($sql) as $part) {
      $table = filter_bot_install_db_statement_table($part);
      if ($table !== '') $map[$table] = trim($part);
    }
    return $map;
  }
}

acl_guard(module_allowed_roles(FILTER_BOT_MODULE_CODE));

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

try {
  $pdo = db();
  $dbName = trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
  if ($dbName === '') {
    throw new RuntimeException('Не удалось определить текущую БД.');
  }

  $tables = FILTER_BOT_TABLES;
  $in = implode(',', array_fill(0, count($tables), '?'));
  $params = array_merge([$dbName], $tables);
  $st = $pdo->prepare("
    SELECT TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = ?
      AND TABLE_NAME IN ($in)
  ");
  $st->execute($params);

  $existingRaw = $st->fetchAll(PDO::FETCH_COLUMN, 0);
  if (!is_array($existingRaw)) $existingRaw = [];
  $existingMap = array_fill_keys(array_map('strval', $existingRaw), true);

  $missing = [];
  foreach ($tables as $table) {
    if (!isset($existingMap[$table])) $missing[] = $table;
  }

  if (!$missing) {
    flash('Таблицы filter_bot уже существуют.', 'ok');
    redirect('/adm/index.php?m=' . FILTER_BOT_MODULE_CODE);
  }

  $installSqlPath = ROOT_PATH . '/adm/modules/' . FILTER_BOT_MODULE_CODE . '/install.sql';
  if (!is_file($installSqlPath)) {
    throw new RuntimeException('Не найден install.sql модуля filter_bot.');
  }

  $installSql = (string)file_get_contents($installSqlPath);
  if (trim($installSql) === '') {
    throw new RuntimeException('install.sql модуля filter_bot пуст.');
  }

  $createMap = filter_bot_install_db_collect_create_map($installSql);
  foreach ($missing as $table) {
    $sql = trim((string)($createMap[$table] ?? ''));
    if ($sql === '') {
      throw new RuntimeException('В install.sql нет CREATE TABLE для ' . $table);
    }
    $pdo->exec($sql);
  }

  audit_log(FILTER_BOT_MODULE_CODE, 'install_db', 'info', ['created' => $missing]);
  flash('Таблицы filter_bot созданы: ' . implode(', ', $missing), 'ok');
} catch (Throwable $e) {
  audit_log(FILTER_BOT_MODULE_CODE, 'install_db', 'error', ['error' => $e->getMessage()]);
  flash('Ошибка создания БД filter_bot: ' . $e->getMessage(), 'danger', 1);
}

redirect('/adm/index.php?m=' . FILTER_BOT_MODULE_CODE);
