<?php
/**
 * FILE: /adm/modules/services/assets/php/services_lib.php
 * ROLE: Вспомогательные функции модуля services
 * NOTES:
 *  - Только функции (процедурный стиль)
 *  - Префикс services_
 */

declare(strict_types=1);

/**
 * services_settings_get()
 * Возвращает настройки (use_specialists, use_time_slots) из таблицы requests_settings.
 *
 * @param PDO $pdo
 * @return array{use_specialists:int,use_time_slots:int}
 */
function services_settings_get(PDO $pdo): array
{
  $row = null;

  try {
    $st = $pdo->query("SELECT use_specialists, use_time_slots FROM " . SERVICES_SETTINGS_TABLE . " WHERE id = 1 LIMIT 1");
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
  } catch (Throwable $e) {
    // игнор
  }

  if (!$row) {
    try {
      $pdo->prepare("INSERT INTO " . SERVICES_SETTINGS_TABLE . " (id, use_specialists, use_time_slots) VALUES (1, 0, 0)")
          ->execute();
    } catch (Throwable $e) {
      // игнор
    }

    return ['use_specialists' => 0, 'use_time_slots' => 0];
  }

  $useSpecialists = (int)($row['use_specialists'] ?? 0);
  $useTimeSlots = (int)($row['use_time_slots'] ?? 0);

  return ['use_specialists' => $useSpecialists, 'use_time_slots' => $useTimeSlots];
}

/**
 * services_is_admin()
 * Проверяет роль admin в массиве ролей.
 *
 * @param array<int,string> $roles
 * @return bool
 */
function services_is_admin(array $roles): bool
{
  return in_array('admin', $roles, true);
}

/**
 * services_is_manager()
 * Проверяет роль manager в массиве ролей.
 *
 * @param array<int,string> $roles
 * @return bool
 */
function services_is_manager(array $roles): bool
{
  return in_array('manager', $roles, true);
}

/**
 * services_is_uncategorized()
 * Проверяет, является ли категория системной "Без категории".
 *
 * @param PDO $pdo
 * @param int $id
 * @return bool
 */
function services_is_uncategorized(PDO $pdo, int $id): bool
{
  if ($id <= 0) return false;

  try {
    $st = $pdo->prepare("
      SELECT id
      FROM " . SERVICES_SPECIALTIES_TABLE . "
      WHERE id = :id AND code = :code
      LIMIT 1
    ");
    $st->execute([
      ':id' => $id,
      ':code' => SERVICES_UNCATEGORIZED_CODE,
    ]);
    $exists = (int)($st->fetchColumn() ?: 0);
    if ($exists > 0) return true;
  } catch (Throwable $e) {
    // ignore (column may not exist)
  }

  $st = $pdo->prepare("
    SELECT id
    FROM " . SERVICES_SPECIALTIES_TABLE . "
    WHERE id = :id AND name = :name
    LIMIT 1
  ");
  $st->execute([
    ':id' => $id,
    ':name' => SERVICES_UNCATEGORIZED_NAME,
  ]);

  return (int)($st->fetchColumn() ?: 0) > 0;
}

/**
 * services_get_uncategorized_id()
 * Возвращает id категории "Без категории", создаёт при необходимости.
 *
 * @param PDO $pdo
 * @param bool $create
 * @return int
 */
function services_get_uncategorized_id(PDO $pdo, bool $create = true): int
{
  $id = 0;

  try {
    $st = $pdo->prepare("
      SELECT id
      FROM " . SERVICES_SPECIALTIES_TABLE . "
      WHERE code = :code
      LIMIT 1
    ");
    $st->execute([':code' => SERVICES_UNCATEGORIZED_CODE]);
    $id = (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    // ignore (column may not exist)
  }

  if ($id <= 0) {
    $st = $pdo->prepare("
      SELECT id
      FROM " . SERVICES_SPECIALTIES_TABLE . "
      WHERE name = :name
      LIMIT 1
    ");
    $st->execute([':name' => SERVICES_UNCATEGORIZED_NAME]);
    $id = (int)($st->fetchColumn() ?: 0);

    if ($id > 0) {
      try {
        $pdo->prepare("
          UPDATE " . SERVICES_SPECIALTIES_TABLE . "
          SET code = :code
          WHERE id = :id
        ")->execute([
          ':code' => SERVICES_UNCATEGORIZED_CODE,
          ':id' => $id,
        ]);
      } catch (Throwable $e) {
        // ignore (column may not exist)
      }
    }
  }

  if ($id <= 0 && $create) {
    try {
      $st = $pdo->prepare("
        INSERT INTO " . SERVICES_SPECIALTIES_TABLE . " (name, status, created_at, code)
        VALUES (:name, 'active', NOW(), :code)
      ");
      $st->execute([
        ':name' => SERVICES_UNCATEGORIZED_NAME,
        ':code' => SERVICES_UNCATEGORIZED_CODE,
      ]);
      $id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
      try {
        $st = $pdo->prepare("
          INSERT INTO " . SERVICES_SPECIALTIES_TABLE . " (name, status, created_at)
          VALUES (:name, 'active', NOW())
        ");
        $st->execute([':name' => SERVICES_UNCATEGORIZED_NAME]);
        $id = (int)$pdo->lastInsertId();
      } catch (Throwable $e) {
        $id = 0;
      }
    }

    if ($id > 0) {
      try {
        $pdo->prepare("
          UPDATE " . SERVICES_SPECIALTIES_TABLE . "
          SET code = :code
          WHERE id = :id
        ")->execute([
          ':code' => SERVICES_UNCATEGORIZED_CODE,
          ':id' => $id,
        ]);
      } catch (Throwable $e) {
        // ignore (column may not exist)
      }
    }
  }

  if ($id > 0) {
    $pdo->prepare("
      UPDATE " . SERVICES_SPECIALTIES_TABLE . "
      SET status = 'active'
      WHERE id = :id
    ")->execute([':id' => $id]);
  }

  return $id;
}

/**
 * services_refresh_uncategorized()
 * Авто-выключение категории "Без категории", если в ней нет услуг.
 *
 * @param PDO $pdo
 * @return void
 */
function services_refresh_uncategorized(PDO $pdo): void
{
  $id = 0;

  try {
    $st = $pdo->prepare("
      SELECT id
      FROM " . SERVICES_SPECIALTIES_TABLE . "
      WHERE code = :code
      LIMIT 1
    ");
    $st->execute([':code' => SERVICES_UNCATEGORIZED_CODE]);
    $id = (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    // ignore
  }

  if ($id <= 0) {
    $st = $pdo->prepare("
      SELECT id
      FROM " . SERVICES_SPECIALTIES_TABLE . "
      WHERE name = :name
      LIMIT 1
    ");
    $st->execute([':name' => SERVICES_UNCATEGORIZED_NAME]);
    $id = (int)($st->fetchColumn() ?: 0);
  }

  if ($id <= 0) return;

  $st = $pdo->prepare("
    SELECT COUNT(*) 
    FROM " . SERVICES_SPECIALTY_SERVICES_TABLE . "
    WHERE specialty_id = :id
  ");
  $st->execute([':id' => $id]);
  $count = (int)($st->fetchColumn() ?: 0);

  $target = $count > 0 ? 'active' : 'disabled';

  $pdo->prepare("
    UPDATE " . SERVICES_SPECIALTIES_TABLE . "
    SET status = :status
    WHERE id = :id
  ")->execute([
    ':status' => $target,
    ':id' => $id,
  ]);
}
