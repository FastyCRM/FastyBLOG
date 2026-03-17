<?php
/**
 * FILE: /adm/modules/services/assets/php/services_category_add.php
 * ROLE: category_add - создание категории
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
$name = trim((string)($_POST['name'] ?? ''));

if ($name === '') {
  audit_log('services', 'category_create', 'warn', [
    'reason' => 'validation',
  ], 'specialty', null, $actorId, $actorRole);
  flash(services_t('services.flash_category_name_required'), 'warn');
  redirect('/adm/index.php?m=services');
}

/**
 * Проверяем дубликат
 */
$st = $pdo->prepare("SELECT id FROM " . SERVICES_SPECIALTIES_TABLE . " WHERE name = ? LIMIT 1");
$st->execute([$name]);
$dupId = (int)($st->fetchColumn() ?: 0);

if ($dupId > 0) {
  audit_log('services', 'category_create', 'warn', [
    'reason' => 'duplicate',
    'name' => $name,
  ], 'specialty', $dupId, $actorId, $actorRole);
  flash(services_t('services.flash_category_duplicate'), 'warn');
  redirect('/adm/index.php?m=services');
}

try {
  $st = $pdo->prepare("
    INSERT INTO " . SERVICES_SPECIALTIES_TABLE . " (name, status, created_at)
    VALUES (:name, 'active', NOW())
  ");
  $st->execute([':name' => $name]);

  $newId = (int)$pdo->lastInsertId();

  audit_log('services', 'category_create', 'info', [
    'id' => $newId,
    'name' => $name,
    'status' => 'active',
  ], 'specialty', $newId, $actorId, $actorRole);

  flash(services_t('services.flash_category_created'), 'ok');
} catch (Throwable $e) {
  audit_log('services', 'category_create', 'error', [
    'name' => $name,
    'error' => $e->getMessage(),
  ], 'specialty', null, $actorId, $actorRole);
  flash(services_t('services.flash_category_create_error'), 'danger', 1);
}

redirect('/adm/index.php?m=services');