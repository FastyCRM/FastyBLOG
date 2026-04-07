<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_install_db.php
 * ROLE: do=install_db — ручное создание отсутствующих таблиц модуля promobot.
 * RULES:
 *  - только POST + CSRF;
 *  - ACL по ролям модуля;
 *  - проверка таблиц в information_schema;
 *  - создание только отсутствующих таблиц из install.sql.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/promobot_i18n.php';

if (!function_exists('promobot_install_db_split_sql')) {
  /**
   * promobot_install_db_split_sql()
   * Делит SQL-скрипт на выражения по `;` с учётом строковых литералов.
   *
   * @param string $sql
   * @return array<int,string>
   */
  function promobot_install_db_split_sql(string $sql): array
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
   * promobot_install_db_statement_table()
   * Возвращает имя таблицы из CREATE TABLE выражения.
   *
   * @param string $statement
   * @return string
   */
  function promobot_install_db_statement_table(string $statement): string
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
   * promobot_install_db_collect_create_map()
   * Собирает map table => CREATE statement из install.sql.
   *
   * @param string $sql
   * @return array<string,string>
   */
  function promobot_install_db_collect_create_map(string $sql): array
  {
    $map = [];
    $parts = promobot_install_db_split_sql($sql);

    foreach ($parts as $part) {
      $table = promobot_install_db_statement_table($part);
      if ($table === '') continue;
      $map[$table] = trim($part);
    }

    return $map;
  }
}

acl_guard(module_allowed_roles(PROMOBOT_MODULE_CODE));

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
}, PROMOBOT_TABLES), static function ($name) {
  return $name !== '';
})));

if (!$tables) {
  audit_log(PROMOBOT_MODULE_CODE, 'install_db', 'warn', [
    'reason' => 'tables_empty',
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);
  flash(promobot_t('promobot.flash_install_tables_empty'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

try {
  $dbName = trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
  if ($dbName === '') {
    throw new RuntimeException(promobot_t('promobot.error_db_name'));
  }

  $in = implode(',', array_fill(0, count($tables), '?'));
  $params = array_merge([$dbName], $tables);

  $st = $pdo->prepare("\n    SELECT TABLE_NAME\n    FROM information_schema.TABLES\n    WHERE TABLE_SCHEMA = ?\n      AND TABLE_NAME IN ($in)\n  ");
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
    audit_log(PROMOBOT_MODULE_CODE, 'install_db', 'info', [
      'status' => 'already_exists',
      'existing' => $tables,
      'created' => [],
    ], PROMOBOT_MODULE_CODE, null, $uid, $role);

    flash(promobot_t('promobot.flash_install_no_need'), 'ok');
    redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
  }

  $installSqlPath = ROOT_PATH . '/adm/modules/' . PROMOBOT_MODULE_CODE . '/install.sql';
  if (!is_file($installSqlPath)) {
    throw new RuntimeException(promobot_t('promobot.error_install_sql_missing'));
  }

  $installSql = (string)file_get_contents($installSqlPath);
  if (trim($installSql) === '') {
    throw new RuntimeException(promobot_t('promobot.error_install_sql_empty'));
  }

  $createMap = promobot_install_db_collect_create_map($installSql);
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
      promobot_t('promobot.error_install_missing_create') . ': ' . implode(', ', $missingStatements)
    );
  }

  audit_log(PROMOBOT_MODULE_CODE, 'install_db', 'info', [
    'status' => 'ok',
    'created' => $created,
    'existing' => array_values(array_diff($tables, $created)),
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  flash(promobot_t('promobot.flash_install_done', [
    'created' => count($created),
    'existing' => (count($tables) - count($created)),
  ]), 'ok');
} catch (Throwable $e) {
  audit_log(PROMOBOT_MODULE_CODE, 'install_db', 'error', [
    'error' => $e->getMessage(),
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  flash(promobot_t('promobot.flash_install_error', ['error' => $e->getMessage()]), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);