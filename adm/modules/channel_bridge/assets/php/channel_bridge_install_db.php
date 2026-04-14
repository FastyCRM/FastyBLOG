<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_install_db.php
 * ROLE: do=install_db — ручное создание отсутствующих таблиц модуля channel_bridge.
 * RULES:
 *  - только POST + CSRF;
 *  - ACL по ролям модуля;
 *  - проверка существования таблиц в information_schema;
 *  - создание только отсутствующих таблиц из install.sql.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_i18n.php';

if (!function_exists('channel_bridge_install_db_split_sql')) {
  /**
   * channel_bridge_install_db_split_sql()
   * Делит SQL-скрипт на выражения по `;` с учётом строковых литералов.
   *
   * @param string $sql
   * @return array<int,string>
   */
  function channel_bridge_install_db_split_sql(string $sql): array
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
        if ($ch === $quote && $prev !== '\\') {
          $quote = '';
        }
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

  /**
   * channel_bridge_install_db_statement_table()
   * Возвращает имя таблицы из CREATE TABLE выражения.
   *
   * @param string $statement
   * @return string
   */
  function channel_bridge_install_db_statement_table(string $statement): string
  {
    if (!preg_match(
      '~^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[a-zA-Z0-9_]+`?\.)?`?([a-zA-Z0-9_]+)`?\s*\(~iu',
      $statement,
      $m
    )) {
      return '';
    }

    return trim((string)($m[1] ?? ''));
  }

  /**
   * channel_bridge_install_db_collect_create_map()
   * Собирает map table => CREATE statement из install.sql.
   *
   * @param string $sql
   * @return array<string,string>
   */
  function channel_bridge_install_db_collect_create_map(string $sql): array
  {
    $map = [];
    $parts = channel_bridge_install_db_split_sql($sql);

    foreach ($parts as $part) {
      $table = channel_bridge_install_db_statement_table($part);
      if ($table === '') continue;
      $map[$table] = trim($part);
    }

    return $map;
  }
}

acl_guard(module_allowed_roles(CHANNEL_BRIDGE_MODULE_CODE));

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

$pdo = db();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

$tables = array_values(array_unique(array_filter(array_map(static function ($name) {
  return trim((string)$name);
}, CHANNEL_BRIDGE_TABLES), static function ($name) {
  return $name !== '';
})));

if (!$tables) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'install_db', 'warn', [
    'reason' => 'tables_empty',
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, $role);
  flash(channel_bridge_t('channel_bridge.flash_install_tables_empty'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
}

try {
  $dbName = trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
  if ($dbName === '') {
    throw new RuntimeException(channel_bridge_t('channel_bridge.error_db_name'));
  }

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
    if (!isset($existingMap[$table])) {
      $missing[] = $table;
    }
  }

  if (!$missing) {
    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'install_db', 'info', [
      'status' => 'already_exists',
      'existing' => $tables,
      'created' => [],
    ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, $role);

    flash(channel_bridge_t('channel_bridge.flash_install_no_need'), 'ok');
    redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
  }

  $installSqlPath = ROOT_PATH . '/adm/modules/' . CHANNEL_BRIDGE_MODULE_CODE . '/install.sql';
  if (!is_file($installSqlPath)) {
    throw new RuntimeException(channel_bridge_t('channel_bridge.error_install_sql_missing'));
  }

  $installSql = (string)file_get_contents($installSqlPath);
  if (trim($installSql) === '') {
    throw new RuntimeException(channel_bridge_t('channel_bridge.error_install_sql_empty'));
  }

  $installStateSqlPath = ROOT_PATH . '/adm/modules/' . CHANNEL_BRIDGE_MODULE_CODE . '/install_state.sql';
  if (is_file($installStateSqlPath)) {
    $installStateSql = (string)file_get_contents($installStateSqlPath);
    if (trim($installStateSql) !== '') {
      $installSql .= "\n\n" . $installStateSql;
    }
  }

  $createMap = channel_bridge_install_db_collect_create_map($installSql);
  $created = [];
  $missingStatements = [];

  foreach ($missing as $table) {
    $sql = trim((string)($createMap[$table] ?? ''));
    if ($sql === '') {
      $missingStatements[] = $table;
      continue;
    }
    $pdo->exec($sql);
    $created[] = $table;
  }

  if ($missingStatements) {
    throw new RuntimeException(
      channel_bridge_t('channel_bridge.error_install_missing_create') . ': ' . implode(', ', $missingStatements)
    );
  }

  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'install_db', 'info', [
    'status' => 'ok',
    'created' => $created,
    'existing' => array_values(array_diff($tables, $created)),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, $role);

  flash(channel_bridge_t('channel_bridge.flash_install_done', [
    'created' => count($created),
    'existing' => (count($tables) - count($created)),
  ]), 'ok');
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'install_db', 'error', [
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, $role);

  flash(channel_bridge_t('channel_bridge.flash_install_error', ['error' => $e->getMessage()]), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
