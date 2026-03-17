<?php
/**
 * FILE: /core/audit.php
 * ROLE: Системный аудит (БД + файл)
 * CONNECTIONS:
 *  - /core/db.php (db)
 *  - /core/config.php (audit.fallback_file)
 *
 * NOTES:
 *  - audit_log() — единая точка логирования событий системы и модулей.
 *  - Пишем в БД и в файл (JSONL).
 *  - Если БД недоступна/ошибка — файл всё равно пишется.
 *  - Логирование не должно падать и не должно мешать бизнес-операциям.
 */

declare(strict_types=1);

/**
 * audit_log()
 * Пишет событие аудита.
 *
 * @param string      $module    Код модуля/подсистемы (например: 'auth', 'users', 'core')
 * @param string      $action    Действие (например: 'login', 'logout', 'create', 'update', 'error')
 * @param string      $level     Уровень ('info'|'warn'|'error')
 * @param array       $payload   Полезная нагрузка (будет JSON в payload)
 * @param string|null $entity    Сущность (например: 'user'), можно null
 * @param int|null    $entityId  ID сущности, можно null
 * @param int|null    $userId    ID пользователя (actor), можно null
 * @param string|null $role      Код роли пользователя (actor role), можно null
 * @param string      $eventType Тип события ('system'|'error'|'access')
 */
function audit_log(
  string $module,
  string $action,
  string $level = 'info',
  array $payload = [],
  ?string $entity = null,
  ?int $entityId = null,
  ?int $userId = null,
  ?string $role = null,
  string $eventType = 'system'
): void {
  $eventType = (string)$eventType;
  if ($eventType === '') {
    $eventType = ($level === 'error') ? 'error' : 'system';
  }
  if (!in_array($eventType, ['system', 'error', 'access'], true)) {
    $eventType = 'system';
  }

  $row = [
    'created_at' => date('Y-m-d H:i:s'),
    'user_id'    => $userId,
    'role'       => $role,
    'module'     => $module,
    'action'     => $action,
    'entity'     => $entity,
    'entity_id'  => $entityId,
    'event_type' => $eventType,
    'level'      => $level,
    'payload'    => $payload,
    'ip'         => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
  ];

  /**
   * 1) Пишем в БД
   */
  try {
    $pdo = db();

    $stmt = $pdo->prepare("
      INSERT INTO audit_log
        (created_at, user_id, role, module, action, entity, entity_id, event_type, level, payload, ip, user_agent)
      VALUES
        (:created_at, :user_id, :role, :module, :action, :entity, :entity_id, :event_type, :level, :payload, :ip, :user_agent)
    ");

    $stmt->execute([
      ':created_at' => $row['created_at'],
      ':user_id'    => $row['user_id'],
      ':role'       => $row['role'],
      ':module'     => $row['module'],
      ':action'     => $row['action'],
      ':entity'     => $row['entity'],
      ':entity_id'  => $row['entity_id'],
      ':event_type' => $row['event_type'],
      ':level'      => $row['level'],
      ':payload'    => json_encode($row['payload'], JSON_UNESCAPED_UNICODE),
      ':ip'         => $row['ip'],
      ':user_agent' => $row['user_agent'],
    ]);

  } catch (Throwable $e) {
    // Падаем в fallback
  }

  /**
   * 2) Файл (JSONL) — пишем всегда, чтобы был двойной лог
   * Ошибки записи игнорируем, чтобы не ронять систему.
   */
  audit_fallback_file_write($row);
}

/**
 * audit_fallback_file_write()
 * Записывает событие в файл fallback.
 *
 * Формат: одна строка JSON на событие.
 *
 * @param array $row Полностью сформированная строка аудита
 */
function audit_fallback_file_write(array $row): void {
  try {
    $cfg = app_config();
    $file = (string)($cfg['audit']['fallback_file'] ?? '');

    if ($file === '') {
      return;
    }

    $line = json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;

    /**
     * @ подавляет warning, LOCK_EX — чтобы строки не перемешивались
     */
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
  } catch (Throwable $e) {
    // Игнорируем полностью
  }
}
