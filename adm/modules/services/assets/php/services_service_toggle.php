<?php
/**
 * FILE: /adm/modules/services/assets/php/services_service_toggle.php
 * ROLE: service_toggle - включение/отключение услуги
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

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
  audit_log('services', 'service_toggle', 'warn', [
    'reason' => 'validation',
  ], 'service', null, $actorId, $actorRole);
  flash(services_t('services.flash_service_invalid_id'), 'warn');
  redirect('/adm/index.php?m=services');
}

/**
 * Загружаем услугу + категорию
 */
$st = $pdo->prepare("
  SELECT s.id, s.status, sp.id AS category_id, sp.status AS category_status
  FROM " . SERVICES_TABLE . " s
  LEFT JOIN " . SERVICES_SPECIALTY_SERVICES_TABLE . " ss ON ss.service_id = s.id
  LEFT JOIN " . SERVICES_SPECIALTIES_TABLE . " sp ON sp.id = ss.specialty_id
  WHERE s.id = :id
  LIMIT 1
");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  audit_log('services', 'service_toggle', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'service', $id, $actorId, $actorRole);
  flash(services_t('services.flash_service_not_found'), 'warn');
  redirect('/adm/index.php?m=services');
}

$from = (string)($row['status'] ?? 'active');
$to = ($from === 'active') ? 'disabled' : 'active';

/**
 * Если категория отключена - не включаем услугу
 */
$catStatus = (string)($row['category_status'] ?? '');
if ($from === 'disabled' && $to === 'active' && $catStatus === 'disabled') {
  audit_log('services', 'service_toggle', 'warn', [
    'reason' => 'category_disabled',
    'id' => $id,
  ], 'service', $id, $actorId, $actorRole);
  flash(services_t('services.flash_service_category_disabled'), 'warn');
  redirect('/adm/index.php?m=services');
}

try {
  $st = $pdo->prepare("UPDATE " . SERVICES_TABLE . " SET status = :status WHERE id = :id");
  $st->execute([
    ':status' => $to,
    ':id' => $id,
  ]);

  audit_log('services', 'service_toggle', 'info', [
    'id' => $id,
    'from' => $from,
    'to' => $to,
  ], 'service', $id, $actorId, $actorRole);

  flash($to === 'active' ? services_t('services.flash_service_enabled') : services_t('services.flash_service_disabled'), 'ok');
} catch (Throwable $e) {
  audit_log('services', 'service_toggle', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'service', $id, $actorId, $actorRole);
  flash(services_t('services.flash_service_toggle_error'), 'danger', 1);
}

redirect('/adm/index.php?m=services');