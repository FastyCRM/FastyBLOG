<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_settings_rules_save.php
 * ROLE: do=settings_rules_save — сохранение словарей badwords/domains в БД.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/stopbot_lib.php';

acl_guard(module_allowed_roles(STOPBOT_MODULE_CODE));

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

$roles = function_exists('auth_user_roles') ? (array)auth_user_roles((int)auth_user_id()) : [];
if (!stopbot_is_manage_role($roles)) {
  flash(stopbot_t('stopbot.flash_access_denied'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$stopWordsList = trim((string)($_POST['stop_words_list'] ?? ''));
$stopDomainsList = trim((string)($_POST['stop_domains_list'] ?? ''));

$pdo = db();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

$res = stopbot_settings_save_rules($pdo, $stopWordsList, $stopDomainsList);

audit_log(STOPBOT_MODULE_CODE, 'settings_rules_save', (($res['ok'] ?? false) ? 'info' : 'warn'), [
  'stop_words_count' => (int)($res['stop_words_count'] ?? 0),
  'stop_domains_count' => (int)($res['stop_domains_count'] ?? 0),
], STOPBOT_MODULE_CODE, null, $uid, $role);

flash(stopbot_t('stopbot.flash_rules_saved', [
  'words' => (int)($res['stop_words_count'] ?? 0),
  'domains' => (int)($res['stop_domains_count'] ?? 0),
]), 'ok');

redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
