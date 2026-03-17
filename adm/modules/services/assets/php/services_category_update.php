<?php
/**
 * FILE: /adm/modules/services/assets/php/services_category_update.php
 * ROLE: category_update - обновление категории
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
$name = trim((string)($_POST['name'] ?? ''));

if ($id <= 0 || $name === '') {
  audit_log('services', 'category_update', 'warn', [
    'reason' => 'validation',
    'id' => $id,
  ], 'specialty', $id > 0 ? $id : null, $actorId, $actorRole);
  flash(services_t('services.flash_category_invalid_or_empty'), 'warn');
  redirect('/adm/index.php?m=services');
}

/**
 * Проверяем существование
 */
$st = $pdo->prepare("SELECT id FROM " . SERVICES_SPECIALTIES_TABLE . " WHERE id = ? LIMIT 1");
$st->execute([$id]);
$exists = (int)($st->fetchColumn() ?: 0);

if ($exists <= 0) {
  audit_log('services', 'category_update', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'specialty', $id, $actorId, $actorRole);
  flash(services_t('services.flash_category_not_found'), 'warn');
  redirect('/adm/index.php?m=services');
}

try {
  $st = $pdo->prepare("
    UPDATE " . SERVICES_SPECIALTIES_TABLE . "
    SET name = :name
    WHERE id = :id
  ");
  $st->execute([
    ':name' => $name,
    ':id' => $id,
  ]);

  audit_log('services', 'category_update', 'info', [
    'id' => $id,
    'name' => $name,
  ], 'specialty', $id, $actorId, $actorRole);

  flash(services_t('services.flash_category_updated'), 'ok');
} catch (Throwable $e) {
  audit_log('services', 'category_update', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'specialty', $id, $actorId, $actorRole);
  flash(services_t('services.flash_category_update_error'), 'danger', 1);
}

redirect('/adm/index.php?m=services');