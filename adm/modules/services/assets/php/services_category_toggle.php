<?php
/**
 * FILE: /adm/modules/services/assets/php/services_category_toggle.php
 * ROLE: category_toggle - включение/отключение категории (с каскадным отключением услуг)
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
  audit_log('services', 'category_toggle', 'warn', [
    'reason' => 'validation',
  ], 'specialty', null, $actorId, $actorRole);
  flash(services_t('services.flash_category_invalid_id'), 'warn');
  redirect('/adm/index.php?m=services');
}

/**
 * Загружаем категорию
 */
$st = $pdo->prepare("SELECT id, name, status FROM " . SERVICES_SPECIALTIES_TABLE . " WHERE id = ? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  audit_log('services', 'category_toggle', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'specialty', $id, $actorId, $actorRole);
  flash(services_t('services.flash_category_not_found'), 'warn');
  redirect('/adm/index.php?m=services');
}

if (services_is_uncategorized($pdo, $id)) {
  services_refresh_uncategorized($pdo);
  flash(services_t('services.flash_uncategorized_auto_managed'), 'warn');
  redirect('/adm/index.php?m=services');
}

$from = (string)($row['status'] ?? 'active');
$to = ($from === 'active') ? 'disabled' : 'active';
$serviceDisabled = 0;

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("
    UPDATE " . SERVICES_SPECIALTIES_TABLE . "
    SET status = :status
    WHERE id = :id
  ");
  $st->execute([
    ':status' => $to,
    ':id' => $id,
  ]);

  /**
   * Если выключаем категорию - отключаем услуги внутри
   */
  if ($to === 'disabled') {
    $st2 = $pdo->prepare("
      UPDATE " . SERVICES_TABLE . " s
      JOIN " . SERVICES_SPECIALTY_SERVICES_TABLE . " ss ON ss.service_id = s.id
      SET s.status = 'disabled'
      WHERE ss.specialty_id = :id
    ");
    $st2->execute([':id' => $id]);
    $serviceDisabled = (int)$st2->rowCount();
  }

  $pdo->commit();

  audit_log('services', 'category_toggle', 'info', [
    'id' => $id,
    'from' => $from,
    'to' => $to,
    'services_disabled' => $serviceDisabled,
  ], 'specialty', $id, $actorId, $actorRole);

  flash($to === 'active' ? services_t('services.flash_category_enabled') : services_t('services.flash_category_disabled'), 'ok');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  audit_log('services', 'category_toggle', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'specialty', $id, $actorId, $actorRole);
  flash(services_t('services.flash_category_toggle_error'), 'danger', 1);
}

redirect('/adm/index.php?m=services');