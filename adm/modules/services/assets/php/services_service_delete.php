<?php
/**
 * FILE: /adm/modules/services/assets/php/services_service_delete.php
 * ROLE: service_delete - полное удаление услуги
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/services_lib.php';
require_once __DIR__ . '/services_i18n.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('services'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $pdo - БД
 */
$pdo = db();

/**
 * $actorId - актер
 */
$actorId = (int)auth_user_id();

/**
 * $actorRole - роль актера
 */
$actorRole = (string)auth_user_role();

/**
 * Входные поля
 */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  audit_log('services', 'service_delete', 'warn', [
    'reason' => 'validation',
  ], 'service', null, $actorId, $actorRole);
  flash(services_t('services.flash_service_invalid_id'), 'warn');
  redirect('/adm/index.php?m=services');
}

/**
 * Загружаем услугу
 */
$st = $pdo->prepare("SELECT id, name FROM " . SERVICES_TABLE . " WHERE id = ? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  audit_log('services', 'service_delete', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'service', $id, $actorId, $actorRole);
  flash(services_t('services.flash_service_not_found'), 'warn');
  redirect('/adm/index.php?m=services');
}

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("DELETE FROM " . SERVICES_USER_SERVICES_TABLE . " WHERE service_id = ?");
  $st->execute([$id]);

  $st = $pdo->prepare("DELETE FROM " . SERVICES_SPECIALTY_SERVICES_TABLE . " WHERE service_id = ?");
  $st->execute([$id]);

  $st = $pdo->prepare("DELETE FROM " . SERVICES_TABLE . " WHERE id = ?");
  $st->execute([$id]);

  services_refresh_uncategorized($pdo);

  $pdo->commit();

  audit_log('services', 'service_delete', 'info', [
    'id' => $id,
  ], 'service', $id, $actorId, $actorRole);

  flash(services_t('services.flash_service_deleted'), 'ok');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  audit_log('services', 'service_delete', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'service', $id, $actorId, $actorRole);
  flash(services_t('services.flash_service_delete_error'), 'danger', 1);
}

redirect('/adm/index.php?m=services');