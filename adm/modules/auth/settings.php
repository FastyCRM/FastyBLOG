<?php
/**
 * FILE: /modules/auth/settings.php
 * ROLE: Паспорт модуля (метаданные, разрешённые do)
 * CONNECTIONS:
 *  - НЕТ (не тянет БД, не тянет auth, не тянет ACL)
 *
 * NOTES:
 *  - settings.php не содержит логики.
 *  - roles/menu/enabled НЕ здесь (это в БД по твоему канону).
 */

declare(strict_types=1);

return [
  'code' => 'auth',
  'name' => 'Авторизация',
  'icon' => '🔑',
  'has_settings' => 0,

  /**
   * Разрешённые действия do (вызовы через /adm/index.php?m=auth&do=...)
   * view (по умолчанию) обрабатывается через auth.php
   */
  'allowed_do' => [
    'login',
    'logout',
  ],
];
