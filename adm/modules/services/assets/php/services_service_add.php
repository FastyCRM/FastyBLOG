<?php
/**
 * FILE: /adm/modules/services/assets/php/services_service_add.php
 * ROLE: service_add - создание услуги
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

if ($name === '') {
  audit_log('services', 'service_create', 'warn', [
    'reason' => 'validation',
  ], 'service', null, $actorId, $actorRole);
  flash(services_t('services.flash_service_name_required'), 'warn');
  redirect('/adm/index.php?m=services');
}

/**
 * Проверяем дубликат
 */
$st = $pdo->prepare("SELECT id FROM " . SERVICES_TABLE . " WHERE name = ? LIMIT 1");
$st->execute([$name]);
$dupId = (int)($st->fetchColumn() ?: 0);

if ($dupId > 0) {
  audit_log('services', 'service_create', 'warn', [
    'reason' => 'duplicate',
    'name' => $name,
  ], 'service', $dupId, $actorId, $actorRole);
  flash(services_t('services.flash_service_duplicate'), 'warn');
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
  $exists = (int)($st->fetchColumn() ?: 0);
  if ($exists <= 0) {
    audit_log('services', 'service_create', 'warn', [
      'reason' => 'category_not_found',
      'category_id' => $categoryId,
    ], 'service', null, $actorId, $actorRole);
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
    INSERT INTO " . SERVICES_TABLE . " (name, price, duration_min, status, created_at)
    VALUES (:name, :price, :duration_min, 'active', NOW())
  ");
  $st->execute([
    ':name' => $name,
    ':price' => ($price > 0 ? $price : null),
    ':duration_min' => $duration,
  ]);

  $newId = (int)$pdo->lastInsertId();

  if ($categoryId > 0) {
    $st = $pdo->prepare("
      INSERT INTO " . SERVICES_SPECIALTY_SERVICES_TABLE . " (specialty_id, service_id)
      VALUES (:sid, :rid)
    ");
    $st->execute([
      ':sid' => $categoryId,
      ':rid' => $newId,
    ]);
  }

  services_refresh_uncategorized($pdo);

  if ($useSpecialists && $validSpecialists) {
    $st = $pdo->prepare("
      INSERT INTO " . SERVICES_USER_SERVICES_TABLE . " (user_id, service_id)
      VALUES (:uid, :sid)
    ");
    foreach ($validSpecialists as $uid) {
      $st->execute([
        ':uid' => (int)$uid,
        ':sid' => $newId,
      ]);
    }
  }

  $pdo->commit();

  audit_log('services', 'service_create', 'info', [
    'id' => $newId,
    'name' => $name,
    'price' => ($price > 0 ? $price : null),
    'duration_min' => $duration,
    'category_id' => $categoryId > 0 ? $categoryId : null,
    'specialists' => $validSpecialists,
  ], 'service', $newId, $actorId, $actorRole);

  flash(services_t('services.flash_service_created'), 'ok');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  audit_log('services', 'service_create', 'error', [
    'name' => $name,
    'error' => $e->getMessage(),
  ], 'service', null, $actorId, $actorRole);
  flash(services_t('services.flash_service_create_error'), 'danger', 1);
}

redirect('/adm/index.php?m=services');