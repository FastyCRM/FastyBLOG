<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_lib.php
 * ROLE: Вспомогательные функции модуля personal_file
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php (константы)
 *  - /core/db.php (db)
 *
 * NOTES:
 *  - Только чистые функции/запросы без побочных эффектов.
 *
 * СПИСОК ФУНКЦИЙ:
 *  - personal_file_is_admin()
 *  - personal_file_is_manager()
 *  - personal_file_is_user()
 *  - personal_file_can_manage()
 *  - personal_file_user_can_access()
 *  - personal_file_get_client()
 *  - personal_file_get_notes()
 *  - personal_file_get_note_files_map()
 *  - personal_file_get_services()
 *  - personal_file_get_access_types()
 *  - personal_file_get_access_ttls()
 *  - personal_file_get_accesses()
 *  - personal_file_storage_dir()
 *  - personal_file_note_dir()
 *  - personal_file_file_url()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once ROOT_PATH . '/adm/modules/clients/settings.php';
require_once ROOT_PATH . '/adm/modules/clients/assets/php/clients_search_lib.php';

/**
 * personal_file_is_admin()
 */
function personal_file_is_admin(array $roles): bool {
  return in_array('admin', $roles, true);
}

/**
 * personal_file_is_manager()
 */
function personal_file_is_manager(array $roles): bool {
  return in_array('manager', $roles, true);
}

/**
 * personal_file_is_user()
 */
function personal_file_is_user(array $roles): bool {
  return in_array('user', $roles, true) || in_array('specialist', $roles, true);
}

/**
 * personal_file_can_manage()
 * Админ/менеджер
 */
function personal_file_can_manage(array $roles): bool {
  return personal_file_is_admin($roles) || personal_file_is_manager($roles);
}

/**
 * personal_file_user_can_access()
 * Проверяет, может ли пользователь видеть вкладку "Доступы".
 *
 * Правило:
 *  - admin/manager всегда
 *  - user/specialist только если есть подтверждённая заявка на него
 */
function personal_file_user_can_access(PDO $pdo, int $clientId, int $userId, array $roles): bool {
  if ($clientId <= 0) return false;
  if ($userId <= 0) return false;
  if (personal_file_can_manage($roles)) return true;

  $st = $pdo->prepare("
    SELECT 1
    FROM " . PERSONAL_FILE_REQUESTS_TABLE . "
    WHERE client_id = :cid
      AND specialist_user_id = :uid
      AND status IN ('confirmed','in_work')
    LIMIT 1
  ");
  $st->execute([':cid' => $clientId, ':uid' => $userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return (bool)$row;
}

/**
 * personal_file_search_clients()
 * Ищет клиентов для личного дела.
 *
 * Правила:
 *  - admin/manager: поиск по всем клиентам
 *  - user/specialist: только клиенты с заявками на текущего пользователя
 *    в статусах confirmed/in_work
 *
 * @param PDO $pdo
 * @param string $query
 * @param int $userId
 * @param array<int,string> $roles
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function personal_file_search_clients(PDO $pdo, string $query, int $userId, array $roles, int $limit = 40): array {
  return clients_search_items($pdo, $query, $userId, $roles, $limit);
}

/**
 * personal_file_get_client()
 */
function personal_file_get_client(PDO $pdo, int $clientId): ?array {
  if ($clientId <= 0) return null;

  $st = $pdo->prepare("
    SELECT
      id,
      first_name,
      last_name,
      middle_name,
      phone,
      email,
      status,
      created_at,
      updated_at,
      inn,
      photo_path,
      birth_date
    FROM " . PERSONAL_FILE_CLIENTS_TABLE . "
    WHERE id = :id
    LIMIT 1
  ");
  $st->execute([':id' => $clientId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/**
 * personal_file_get_notes()
 */
function personal_file_get_notes(PDO $pdo, int $clientId, int $limit = 200, ?string $dateFrom = null, ?string $dateTo = null): array {
  if ($clientId <= 0) return [];

  $where = "n.client_id = :cid";
  $params = [':cid' => $clientId];

  if ($dateFrom) {
    $where .= " AND n.created_at >= :df";
    $params[':df'] = $dateFrom . ' 00:00:00';
  }
  if ($dateTo) {
    $where .= " AND n.created_at <= :dt";
    $params[':dt'] = $dateTo . ' 23:59:59';
  }

  $st = $pdo->prepare("
    SELECT
      n.id,
      n.client_id,
      n.user_id,
      u.name AS user_name,
      n.note_text,
      n.created_at,
      n.updated_at
    FROM " . PERSONAL_FILE_NOTES_TABLE . " n
    LEFT JOIN users u ON u.id = n.user_id
    WHERE {$where}
    ORDER BY n.created_at DESC
    LIMIT " . (int)$limit . "
  ");
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * personal_file_get_note_files_map()
 * Возвращает map note_id => files[]
 */
function personal_file_get_note_files_map(PDO $pdo, array $noteIds): array {
  if (!$noteIds) return [];

  $ids = array_values(array_filter(array_map('intval', $noteIds)));
  if (!$ids) return [];

  $in = implode(',', $ids);
  $st = $pdo->query("
    SELECT
      id,
      note_id,
      client_id,
      file_name,
      orig_name,
      file_path,
      file_ext,
      file_size,
      file_mime,
      is_image,
      created_at
    FROM " . PERSONAL_FILE_NOTE_FILES_TABLE . "
    WHERE note_id IN ({$in})
    ORDER BY id ASC
  ");
  $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

  $map = [];
  foreach ($rows as $r) {
    $nid = (int)($r['note_id'] ?? 0);
    if ($nid <= 0) continue;
    if (!isset($map[$nid])) $map[$nid] = [];
    $map[$nid][] = $r;
  }
  return $map;
}

/**
 * personal_file_get_services()
 * История услуг клиента (из request_invoices + items)
 */
function personal_file_get_services(PDO $pdo, int $clientId, int $limit = 300): array {
  if ($clientId <= 0) return [];

  $st = $pdo->prepare("
    SELECT
      ii.id,
      ii.service_name,
      ii.qty,
      ii.price,
      ii.total,
      ii.created_at,
      inv.invoice_date,
      inv.request_id
    FROM " . PERSONAL_FILE_REQUEST_INVOICE_ITEMS_TABLE . " ii
    JOIN " . PERSONAL_FILE_REQUEST_INVOICES_TABLE . " inv ON inv.id = ii.invoice_id
    WHERE inv.client_id = :cid
    ORDER BY ii.created_at DESC
    LIMIT " . (int)$limit . "
  ");
  $st->execute([':cid' => $clientId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * personal_file_get_access_types()
 */
function personal_file_get_access_types(PDO $pdo): array {
  $st = $pdo->query("
    SELECT id, name, status
    FROM " . PERSONAL_FILE_ACCESS_TYPES_TABLE . "
    WHERE status = 'active'
    ORDER BY name ASC, id ASC
  ");
  return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * personal_file_get_access_ttls()
 */
function personal_file_get_access_ttls(PDO $pdo): array {
  $st = $pdo->query("
    SELECT id, name, months, is_permanent, status
    FROM " . PERSONAL_FILE_ACCESS_TTLS_TABLE . "
    WHERE status = 'active'
    ORDER BY sort ASC, id ASC
  ");
  return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * personal_file_get_accesses()
 */
function personal_file_get_accesses(PDO $pdo, int $clientId): array {
  if ($clientId <= 0) return [];

  $st = $pdo->prepare("
    SELECT
      a.id,
      a.client_id,
      a.type_id,
      t.name AS type_name,
      a.ttl_id,
      ttl.name AS ttl_name,
      a.expires_at,
      a.created_at
    FROM " . PERSONAL_FILE_ACCESS_TABLE . " a
    LEFT JOIN " . PERSONAL_FILE_ACCESS_TYPES_TABLE . " t ON t.id = a.type_id
    LEFT JOIN " . PERSONAL_FILE_ACCESS_TTLS_TABLE . " ttl ON ttl.id = a.ttl_id
    WHERE a.client_id = :cid
    ORDER BY a.created_at DESC, a.id DESC
  ");
  $st->execute([':cid' => $clientId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * personal_file_storage_dir()
 * Корневая папка для файлов клиента.
 */
function personal_file_storage_dir(int $clientId): string {
  return ROOT_PATH . '/storage/clients_file/' . $clientId;
}

/**
 * personal_file_note_dir()
 * Папка заметок по дате.
 */
function personal_file_note_dir(int $clientId, string $date): string {
  return personal_file_storage_dir($clientId) . '/' . $date;
}

/**
 * personal_file_file_url()
 * URL для скачивания файла заметки.
 */
function personal_file_file_url(int $fileId): string {
  return url('/adm/index.php?m=personal_file&do=file_get&id=' . $fileId);
}
