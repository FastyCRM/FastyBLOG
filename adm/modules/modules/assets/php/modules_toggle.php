<?php
/**
 * FILE: /adm/modules/modules/assets/php/modules_toggle.php
 * ROLE: Вкл / выкл модуля
 * CONNECTIONS:
 *  - db()
 *  - csrf_check()
 *  - flash(), redirect()
 *  - module_allowed_roles(), acl_guard()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/modules_i18n.php';

/**
 * ACL: доступ к действию определяет БД (modules.roles) для модуля modules.
 */
acl_guard(module_allowed_roles('modules'));

/**
 * CSRF: проверяем токен из POST.
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $id — id модуля.
 */
$id = (int)($_POST['id'] ?? 0);

$pdo = db();

/**
 * Actor.
 */
$actorId = (int)auth_user_id();
$actorRole = (string)auth_user_role();

try {
  /**
   * $mod — текущие данные модуля.
   */
  $stmt = $pdo->prepare("SELECT code, enabled, name FROM modules WHERE id = ? LIMIT 1");
  $stmt->execute([$id]);
  $mod = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$mod) {
    audit_log('modules', 'toggle', 'warn', [
      'reason' => 'not_found',
      'id' => $id,
    ], 'module', $id, $actorId, $actorRole);
    flash(modules_t('modules.flash_not_found'), 'danger', 1);
    redirect('/adm/index.php?m=modules');
  }

  /**
   * $code — код модуля.
   */
  $code = (string)($mod['code'] ?? '');

  if (function_exists('modules_is_protected') && modules_is_protected($code)) {
    audit_log('modules', 'toggle', 'warn', [
      'reason' => 'protected',
      'id' => $id,
      'code' => $code,
    ], 'module', $id, $actorId, $actorRole);
    flash(modules_t('modules.flash_protected'), 'danger', 1);
    redirect('/adm/index.php?m=modules');
  }

  /**
   * $new — новое значение enabled.
   */
  $new = ((int)$mod['enabled'] === 1) ? 0 : 1;

  $upd = $pdo->prepare("UPDATE modules SET enabled = ? WHERE id = ? LIMIT 1");
  $upd->execute([$new, $id]);

  audit_log('modules', 'toggle', 'info', [
    'id' => $id,
    'code' => $code,
    'enabled' => (int)$new,
  ], 'module', $id, $actorId, $actorRole);

  $flashText = $new
    ? modules_t('modules.flash_enabled', ['name' => (string)$mod['name']])
    : modules_t('modules.flash_disabled', ['name' => (string)$mod['name']]);

  flash($flashText, $new ? 'ok' : 'warn');
} catch (Throwable $e) {
  audit_log('modules', 'toggle', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'module', $id, $actorId, $actorRole);
  flash(modules_t('modules.flash_update_error'), 'danger', 1);
}

redirect('/adm/index.php?m=modules');
