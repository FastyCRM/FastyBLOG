<?php
/**
 * FILE: /adm/modules/services/assets/php/services_service_update.php
 * ROLE: service_update - обновление услуги
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
$name = trim((string)($_POST['name'] ?? ''));
$price = (int)($_POST['price'] ?? 0);
if ($price < 0) $price = 0;
$duration = (int)($_POST['duration_min'] ?? 0);
if ($duration < 0) $duration = 0;
$categoryId = (int)($_POST['category_id'] ?? 0);
$specIn = $_POST['specialist_ids'] ?? [];
$specialistIds = [];

if (is_array($specIn)) {
  foreach ($specIn as $sid) {
    $sid = (int)$sid;
    if ($sid > 0) $specialistIds[] = $sid;
  }
}
$specialistIds = array_values(array_unique($specialistIds));

if ($id <= 0 || $name === '') {
  audit_log('services', 'service_update', 'warn', [
    'reason' => 'validation',
    'id' => $id,
  ], 'service', $id > 0 ? $id : null, $actorId, $actorRole);
  flash(services_t('services.flash_service_invalid_or_empty'), 'warn');
  redirect('/adm/index.php?m=services');
}

/**
 * Проверяем существование
 */
$st = $pdo->prepare("SELECT id FROM " . SERVICES_TABLE . " WHERE id = ? LIMIT 1");
$st->execute([$id]);
$exists = (int)($st->fetchColumn() ?: 0);

if ($exists <= 0) {
  audit_log('services', 'service_update', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'service', $id, $actorId, $actorRole);
  flash(services_t('services.flash_service_not_found'), 'warn');
  redirect('/adm/index.php?m=services');
}

/**
 * Категория по умолчанию: "Без категории"
 */
if ($categoryId <= 0) {
  $categoryId = services_get_uncategorized_id($pdo, true);
}

/**
 * Категория (опционально)
 */
if ($categoryId > 0) {
  $st = $pdo->prepare("SELECT id FROM " . SERVICES_SPECIALTIES_TABLE . " WHERE id = ? AND status = 'active' LIMIT 1");
  $st->execute([$categoryId]);
  $catExists = (int)($st->fetchColumn() ?: 0);
  if ($catExists <= 0) {
    audit_log('services', 'service_update', 'warn', [
      'reason' => 'category_not_found',
      'category_id' => $categoryId,
      'id' => $id,
    ], 'service', $id, $actorId, $actorRole);
    flash(services_t('services.flash_category_not_found'), 'warn');
    redirect('/adm/index.php?m=services');
  }
}

/**
 * Настройки (use_specialists)
 */
$settings = services_settings_get($pdo);
$useSpecialists = ((int)($settings['use_specialists'] ?? 0) === 1);
$useTimeSlots = ($useSpecialists && ((int)($settings['use_time_slots'] ?? 0) === 1));

/**
 * Нормализуем длительность
 */
if ($useTimeSlots) {
  if ($duration <= 0) $duration = 30;
} else {
  $duration = 30;
}

/**
 * Валидируем специалистов
 */
$validSpecialists = [];
if ($useSpecialists && $specialistIds) {
  $in = implode(',', array_fill(0, count($specialistIds), '?'));
  $st = $pdo->prepare("
    SELECT u.id
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'specialist' AND u.status = 'active' AND u.id IN ($in)
  ");
  $st->execute($specialistIds);
  $validSpecialists = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("
    UPDATE " . SERVICES_TABLE . "
    SET name = :name,
        price = :price,
        duration_min = :duration_min
    WHERE id = :id
  ");
  $st->execute([
    ':name' => $name,
    ':price' => ($price > 0 ? $price : null),
    ':duration_min' => $duration,
    ':id' => $id,
  ]);

  $st = $pdo->prepare("DELETE FROM " . SERVICES_SPECIALTY_SERVICES_TABLE . " WHERE service_id = ?");
  $st->execute([$id]);

  if ($categoryId > 0) {
    $st = $pdo->prepare("
      INSERT INTO " . SERVICES_SPECIALTY_SERVICES_TABLE . " (specialty_id, service_id)
      VALUES (:sid, :rid)
    ");
    $st->execute([
      ':sid' => $categoryId,
      ':rid' => $id,
    ]);
  }

  services_refresh_uncategorized($pdo);

  if ($useSpecialists) {
    $st = $pdo->prepare("DELETE FROM " . SERVICES_USER_SERVICES_TABLE . " WHERE service_id = ?");
    $st->execute([$id]);

    if ($validSpecialists) {
      $st = $pdo->prepare("
        INSERT INTO " . SERVICES_USER_SERVICES_TABLE . " (user_id, service_id)
        VALUES (:uid, :sid)
      ");
      foreach ($validSpecialists as $uid) {
        $st->execute([
          ':uid' => (int)$uid,
          ':sid' => $id,
        ]);
      }
    }
  }

  $pdo->commit();

  audit_log('services', 'service_update', 'info', [
    'id' => $id,
    'name' => $name,
    'price' => ($price > 0 ? $price : null),
    'duration_min' => $duration,
    'category_id' => $categoryId > 0 ? $categoryId : null,
    'specialists' => $validSpecialists,
  ], 'service', $id, $actorId, $actorRole);

  flash(services_t('services.flash_service_updated'), 'ok');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  audit_log('services', 'service_update', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'service', $id, $actorId, $actorRole);
  flash(services_t('services.flash_service_update_error'), 'danger', 1);
}

redirect('/adm/index.php?m=services');