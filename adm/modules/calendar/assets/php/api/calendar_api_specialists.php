<?php
/**
 * FILE: /adm/modules/calendar/assets/php/api/calendar_api_specialists.php
 * ROLE: api_specialists — список специалистов
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../calendar_lib.php';

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $st — запрос специалистов
 */
$st = $pdo->prepare("
  SELECT u.id, u.name
  FROM " . CALENDAR_USERS_TABLE . " u
  JOIN " . CALENDAR_USER_ROLES_TABLE . " ur ON ur.user_id = u.id
  JOIN " . CALENDAR_ROLES_TABLE . " r ON r.id = ur.role_id
  WHERE r.code = 'specialist' AND u.status = 'active'
  ORDER BY u.name ASC
");
$st->execute();

/**
 * $list — список специалистов
 */
$list = $st->fetchAll(PDO::FETCH_ASSOC);

json_ok([
  'items' => $list,
]);
