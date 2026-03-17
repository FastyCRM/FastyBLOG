<?php
/**
 * FILE: /adm/index.php
 * ROLE: Admin entrypoint (shell)
 * CONNECTIONS:
 *  - /core/bootstrap.php (ROOT_PATH, DOC_ROOT, BASE_URL, url(), fs_path(), h(), core includes)
 *  - /adm/view/index.php (layout + dispatcher)
 *
 * NOTES:
 *  - Этот файл — ЕДИНСТВЕННАЯ точка входа админки.
 *  - Сначала определяем ROOT_PATH.
 *  - Потом подключаем /core/bootstrap.php железно через ROOT_PATH.
 *  - Потом подключаем layout.
 */

declare(strict_types=1);

/**
 * ROOT_PATH нужен, чтобы одинаково работать:
 * 1) когда /index.php подключает /adm/index.php
 * 2) когда /adm/index.php открывают напрямую
 */
if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__));
}

/**
 * Bootstrap ядра проекта (пути, helpers, core includes, session/auth restore)
 */
require_once ROOT_PATH . '/core/bootstrap.php';

/**
 * Layout админки
 */
require_once ROOT_PATH . '/adm/view/index.php';
