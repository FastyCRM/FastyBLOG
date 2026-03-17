<?php
/**
 * FILE: /adm/core/bootstrap.php
 * ROLE: Bootstrap admin area (paths, helpers + base core includes)
 * CONNECTIONS:
 *  - PHP: $_SERVER['DOCUMENT_ROOT'], $_SERVER['SCRIPT_NAME']
 *  - PROJECT ROOT: /core/*.php (db/session/csrf/cookies/audit/auth/modules/acl/response)
 *
 * NOTES:
 *  - Этот bootstrap — единая точка старта админки.
 *  - Содержит только:
 *      1) пути/BASE_URL
 *      2) базовые helper-функции (h, url, fs_path)
 *      3) подключение атомарных core-файлов
 *      4) запуск сессии и восстановление авторизации (auth_restore)
 *  - Никакого HTML, никакого роутинга m/do, никакой бизнес-логики модулей.
 */

declare(strict_types=1);

/**
 * ROOT_PATH — корень проекта.
 * Нужен на случай, если bootstrap подключат не через /adm/index.php.
 *
 * /adm/core/bootstrap.php -> корень = dirname(__DIR__, 2)
 */
if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__, 2));
}

/**
 * Абсолютный путь к документ-руту сервера (MAMP/host совместимо)
 */
define('DOC_ROOT', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\'));

/**
 * URL-база проекта.
 * Работает и в корне домена, и в подпапке.
 *
 * Пример:
 *  - если открыли /adm/index.php → BASE_URL = ''
 *  - если открыли /crm2026new/adm/index.php → BASE_URL = '/crm2026new'
 */
$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$base = str_replace('\\', '/', dirname(dirname($script))); // /.../adm/index.php -> /...
$base = $base === '/' ? '' : rtrim($base, '/');
define('BASE_URL', $base);

/**
 * HTML escape (весь проект использует единый экранировщик)
 */
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL builder (всегда с учётом BASE_URL)
 * Пример: url('/adm/index.php') -> '/crm2026new/adm/index.php' или '/adm/index.php'
 */
function url(string $path): string {
  if ($path === '') return BASE_URL ?: '/';
  return (BASE_URL . '/' . ltrim($path, '/'));
}

/**
 * FS path builder from DOC_ROOT
 * Пример: fs_path('/adm/index.php') -> '/Applications/MAMP/htdocs/adm/index.php'
 */
function fs_path(string $path): string {
  return DOC_ROOT . '/' . ltrim($path, '/');
}

/* ========================================================================
 * БАЗОВЫЕ CORE-ПОДКЛЮЧЕНИЯ (атомарно, без комбайнов)
 * ========================================================================
 *
 * Правило:
 *  - Один файл — одна ответственность.
 *  - Bootstrap подключает инструменты, но не делает работу модулей.
 */

/**
 * Конфиг проекта (БД, секреты, параметры)
 * Возвращает массив; сохраняем его в глобальный контейнер.
 */
$GLOBALS['APP_CONFIG'] = require ROOT_PATH . '/core/config.php';

/**
 * Ответы/редиректы/HTTP-коды — базовая инфраструктура.
 */
require_once ROOT_PATH . '/core/response.php';

/**
 * Сессии — отдельный файл.
 * ВАЖНО: session_boot() должен быть вызван один раз в bootstrap.
 */
require_once ROOT_PATH . '/core/session.php';

// Flash-сообщения — отдельный файл.

require_once ROOT_PATH . '/core/flash.php';

/**
 * CSRF — отдельный файл.
 * Использует session_get/session_set (из session.php).
 */
require_once ROOT_PATH . '/core/csrf.php';

/**
 * I18N вЂ” СЃР»РѕРІР°СЂРё РёРЅС‚РµСЂС„РµР№СЃР° Рё С„СѓРЅРєС†РёСЏ t().
 */
require_once ROOT_PATH . '/core/i18n.php';

/**
 * Cookies/remember — отдельный файл.
 * Использует APP_CONFIG['security']['app_secret'].
 */
require_once ROOT_PATH . '/core/cookies.php';

/**
 * База данных — отдельный файл (PDO).
 */
require_once ROOT_PATH . '/core/db.php';

/**
 * Аудит/логирование — отдельный файл.
 * Правило: БД first, fallback в файл если БД недоступна.
 */
require_once ROOT_PATH . '/core/audit.php';

/**
 * PDF-сервис — генерация и выдача PDF.
 */
require_once ROOT_PATH . '/core/pdf.php';

/**
 * Авторизация — отдельный файл.
 * Внутри: login/logout/restore/sessions/attempts.
 */
require_once ROOT_PATH . '/core/auth.php';

/**
 * Telegram-инфраструктура (транспортный слой + sendSystemTG).
 */
require_once ROOT_PATH . '/core/telegram.php';

/**
 * Глобальные обработчики ошибок/исключений (логируем в audit_log)
 */
if (!defined('APP_ERROR_HANDLERS_SET')) {
  define('APP_ERROR_HANDLERS_SET', true);

  $auditGuard = false;

  $auditLogError = function (string $action, array $payload, string $level = 'error') use (&$auditGuard): void {
    if ($auditGuard) return;
    $auditGuard = true;
    try {
      if (!function_exists('audit_log')) return;
      $uid = function_exists('auth_user_id') ? auth_user_id() : null;
      $role = function_exists('auth_user_role') ? auth_user_role() : null;
      audit_log('core', $action, $level, $payload, null, null, $uid, $role);
    } catch (Throwable $e) {
      // Не даём логированию ронять систему
    } finally {
      $auditGuard = false;
    }
  };

  set_error_handler(function ($severity, $message, $file = '', $line = 0) use ($auditLogError) {
    if (!(error_reporting() & $severity)) {
      return false;
    }

    $level = in_array($severity, [E_WARNING, E_USER_WARNING, E_NOTICE, E_USER_NOTICE], true) ? 'warn' : 'error';
    $auditLogError('php_error', [
      'severity' => $severity,
      'message'  => (string)$message,
      'file'     => (string)$file,
      'line'     => (int)$line,
    ], $level);

    return false;
  });

  set_exception_handler(function (Throwable $e) use ($auditLogError) {
    $auditLogError('exception', [
      'class'   => get_class($e),
      'message' => $e->getMessage(),
      'code'    => $e->getCode(),
      'file'    => $e->getFile(),
      'line'    => $e->getLine(),
      'trace'   => $e->getTraceAsString(),
    ], 'error');

    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Internal Server Error';
    exit;
  });

  register_shutdown_function(function () use ($auditLogError) {
    $err = error_get_last();
    if (!$err) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) return;

    $auditLogError('fatal_error', [
      'type'    => (int)$err['type'],
      'message' => (string)$err['message'],
      'file'    => (string)$err['file'],
      'line'    => (int)$err['line'],
    ], 'error');
  });
}

/**
 * Модули и ACL — отдельные файлы.
 * modules.php читает БД (enabled/menu/roles)
 * acl.php проверяет доступ.
 */
require_once ROOT_PATH . '/core/modules.php';
require_once ROOT_PATH . '/core/acl.php';

/* ========================================================================
 * ИНИЦИАЛИЗАЦИЯ СИСТЕМЫ (минимум)
 * ======================================================================== */

/**
 * Стартуем PHP-сессию.
 * Внутри session_boot() настраивается cookie и вызывается session_start().
 */
session_boot();

/**
 * РџРµСЂРµРєР»СЋС‡РµРЅРёРµ СЏР·С‹РєР° РёР· UI (POST С„РѕСЂРјС‹ shell).
 * РћР±СЂР°Р±Р°С‚С‹РІР°РµС‚СЃСЏ С†РµРЅС‚СЂР°Р»РёР·РѕРІР°РЅРЅРѕ РІ bootstrap, С‡С‚РѕР±С‹ РЅРµ РґСѓР±Р»РёСЂРѕРІР°С‚СЊ РІ РјРѕРґСѓР»СЏС….
 */
if (function_exists('i18n_handle_switch_request')) {
  i18n_handle_switch_request();
}

/**
 * Р‘Р°Р·РѕРІР°СЏ Р·Р°РіСЂСѓР·РєР° СЏР·С‹РєР° (common-СЃР»РѕРІР°СЂСЊ).
 * РњРѕРґСѓР»СЊРЅС‹Р№ СЃР»РѕРІР°СЂСЊ РґРѕРіСЂСѓР¶Р°РµС‚СЃСЏ РІ adm/view/index.php РїРѕСЃР»Рµ РѕРїСЂРµРґРµР»РµРЅРёСЏ $m.
 */
if (function_exists('load_language')) {
  load_language((string)($_SESSION['lang'] ?? 'ru'));
}

/**
 * Восстанавливаем авторизацию (если есть активная сессия/remember-cookie).
 * ВАЖНО: эта функция должна быть "мягкой" — не падать, если БД недоступна.
 */
auth_restore();
