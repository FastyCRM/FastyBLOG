<?php
/**
 * FILE: /core/acl.php
 * ROLE: Контроль доступа (ACL) по ролям
 * CONNECTIONS:
 *  - /core/response.php (http_401/http_403)
 *  - /core/auth.php (auth_user_id/auth_user_roles)
 *  - /core/audit.php (audit_log)
 *
 * NOTES:
 *  - ACL живёт в БД (modules.roles как JSON-массив).
 *  - settings.php не содержит ACL.
 *  - Эта подсистема ничего не знает о меню — только доступ.
 */

declare(strict_types=1);

/**
 * acl_guard()
 * Охранник доступа: если пользователь не подходит по ролям — 403.
 *
 * @param array<int, string> $allowedRoles Роли, которым разрешён доступ
 */
function acl_guard(array $allowedRoles): void {
  $uid = auth_user_id();
  if (!$uid) {
    audit_log('acl', 'deny', 'warn', ['reason' => 'unauthorized', 'allowed' => $allowedRoles]);
    http_401('Unauthorized');
  }

  $allowedRoles = array_values(array_filter(array_map('strval', $allowedRoles)));

  /**
   * Если список ролей пустой — доступ запрещён всем.
   */
  if (!$allowedRoles) {
    audit_log('acl', 'deny', 'warn', ['reason' => 'no_allowed_roles'], null, null, $uid, null);
    http_403('Forbidden');
  }

  $userRoles = auth_user_roles($uid);

  if (!acl_roles_intersect($userRoles, $allowedRoles)) {
    audit_log(
      'acl',
      'deny',
      'warn',
      [
        'reason' => 'role_mismatch',
        'user_roles' => $userRoles,
        'allowed_roles' => $allowedRoles,
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
      ],
      null,
      null,
      $uid,
      $userRoles[0] ?? null
    );

    http_403('Forbidden');
  }
}

/**
 * acl_roles_intersect()
 * Проверяет пересечение ролей пользователя и разрешённых ролей.
 *
 * @param array<int, string> $userRoles
 * @param array<int, string> $allowedRoles
 */
function acl_roles_intersect(array $userRoles, array $allowedRoles): bool {
  $set = array_fill_keys(array_map('strval', $userRoles), true);

  foreach ($allowedRoles as $r) {
    $r = (string)$r;
    if (isset($set[$r])) {
      return true;
    }
  }

  return false;
}
