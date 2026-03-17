<?php
/**
 * FILE: /adm/modules/services/assets/php/services_category_delete.php
 * ROLE: category_delete - полное удаление категории (и ее услуг)
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
  audit_log('services', 'category_delete', 'warn', [
    'reason' => 'validation',
  ], 'specialty', null, $actorId, $actorRole);
  flash(services_t('services.flash_category_invalid_id'), 'warn');
  redirect('/adm/index.php?m=services');
}

/**
 * Загружаем категорию
 */
$st = $pdo->prepare("SELECT id, name FROM " . SERVICES_SPECIALTIES_TABLE . " WHERE id = ? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  audit_log('services', 'category_delete', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'specialty', $id, $actorId, $actorRole);
  flash(services_t('services.flash_category_not_found'), 'warn');
  redirect('/adm/index.php?m=services');
}

if (services_is_uncategorized($pdo, $id)) {
  audit_log('services', 'category_delete', 'warn', [
    'reason' => 'uncategorized_protected',
    'id' => $id,
  ], 'specialty', $id, $actorId, $actorRole);
  flash(services_t('services.flash_uncategorized_protected_delete'), 'warn');
  redirect('/adm/index.php?m=services');
}

try {
  $pdo->beginTransaction();

  /**
   * Услуги категории
   */
  $st = $pdo->prepare("
    SELECT service_id
    FROM " . SERVICES_SPECIALTY_SERVICES_TABLE . "
    WHERE specialty_id = ?
  ");
  $st->execute([$id]);
  $serviceIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

  if ($serviceIds) {
    $in = implode(',', array_fill(0, count($serviceIds), '?'));

    $st = $pdo->prepare("DELETE FROM " . SERVICES_USER_SERVICES_TABLE . " WHERE service_id IN ($in)");
    $st->execute($serviceIds);

    $st = $pdo->prepare("DELETE FROM " . SERVICES_SPECIALTY_SERVICES_TABLE . " WHERE service_id IN ($in)");
    $st->execute($serviceIds);

    $st = $pdo->prepare("DELETE FROM " . SERVICES_TABLE . " WHERE id IN ($in)");
    $st->execute($serviceIds);
  }

  $st = $pdo->prepare("DELETE FROM " . SERVICES_SPECIALTIES_TABLE . " WHERE id = ?");
  $st->execute([$id]);

  $pdo->commit();

  audit_log('services', 'category_delete', 'info', [
    'id' => $id,
    'services_deleted' => $serviceIds,
  ], 'specialty', $id, $actorId, $actorRole);

  flash(services_t('services.flash_category_deleted'), 'ok');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  audit_log('services', 'category_delete', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'specialty', $id, $actorId, $actorRole);
  flash(services_t('services.flash_category_delete_error'), 'danger', 1);
}

redirect('/adm/index.php?m=services');