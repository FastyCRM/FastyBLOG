<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_search_lib.php
 * ROLE: Общие функции поиска клиентов (единый источник для API/модулей)
 * CONNECTIONS:
 *  - /adm/modules/clients/settings.php
 *  - /adm/modules/clients/assets/php/clients_i18n.php
 *
 * СПИСОК ФУНКЦИЙ:
 *  - clients_search_has_role()
 *  - clients_search_can_manage()
 *  - clients_search_can_limited()
 *  - clients_search_items()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/clients_i18n.php';

/**
 * clients_search_has_role()
 * Проверяет наличие кода роли в массиве ролей пользователя.
 *
 * @param array<int,string> $roles
 * @param string $code
 * @return bool
 */
function clients_search_has_role(array $roles, string $code): bool {
  $need = strtolower(trim($code));
  if ($need === '') return false;

  foreach ($roles as $r) {
    if (strtolower(trim((string)$r)) === $need) return true;
  }
  return false;
}

/**
 * clients_search_can_manage()
 * Определяет, может ли пользователь видеть всех клиентов (admin/manager).
 *
 * @param array<int,string> $roles
 * @return bool
 */
function clients_search_can_manage(array $roles): bool {
  return clients_search_has_role($roles, 'admin') || clients_search_has_role($roles, 'manager');
}

/**
 * clients_search_can_limited()
 * Определяет, действует ли ограниченный поиск по назначенным клиентам.
 *
 * @param array<int,string> $roles
 * @return bool
 */
function clients_search_can_limited(array $roles): bool {
  return clients_search_has_role($roles, 'user') || clients_search_has_role($roles, 'specialist');
}

/**
 * clients_search_items()
 * Выполняет поиск клиентов по строке запроса с ролевыми ограничениями.
 *
 * Правила:
 *  - admin/manager: поиск по всем клиентам.
 *  - user/specialist: только клиенты, где есть заявки на пользователя
 *    в статусах confirmed/in_work.
 *
 * @param PDO $pdo
 * @param string $query
 * @param int $userId
 * @param array<int,string> $roles
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function clients_search_items(PDO $pdo, string $query, int $userId, array $roles, int $limit = 20): array {
  /**
   * $limitSafe - безопасный лимит.
   */
  $limitSafe = (int)$limit;
  if ($limitSafe < 1) $limitSafe = 1;
  if ($limitSafe > 100) $limitSafe = 100;

  /**
   * $queryTrim - очищенная строка поиска.
   */
  $queryTrim = trim($query);
  if ($queryTrim === '') return [];

  /**
   * $queryDigits - только цифры из запроса.
   */
  $queryDigits = preg_replace('/\D+/', '', $queryTrim) ?? '';
  /**
   * $isNumericQuery - запрос состоит только из телефонных/числовых символов.
   */
  $isNumericQuery = ($queryDigits !== '' && preg_match('/^[\s\+\-\(\)\.\d]+$/u', $queryTrim) === 1);

  /**
   * $whereParts - список условий OR для поиска.
   */
  $whereParts = [];
  /**
   * $searchParams - параметры поиска.
   */
  $searchParams = [];

  if ($isNumericQuery) {
    if (strlen($queryDigits) >= 4) {
      $whereParts[] = "CAST(c.inn AS CHAR) LIKE ?";
      $searchParams[] = $queryDigits . '%';
      $phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
      $whereParts[] = $phoneExpr . " LIKE ?";
      $searchParams[] = '%' . $queryDigits . '%';
    }
  } else {
    if (mb_strlen($queryTrim, 'UTF-8') < 2) {
      return [];
    }

    $like = '%' . $queryTrim . '%';
    $whereParts[] = "c.first_name LIKE ?";
    $searchParams[] = $like;
    $whereParts[] = "c.last_name LIKE ?";
    $searchParams[] = $like;
    $whereParts[] = "c.middle_name LIKE ?";
    $searchParams[] = $like;
    $whereParts[] = "CONCAT_WS(' ', c.last_name, c.first_name, c.middle_name) LIKE ?";
    $searchParams[] = $like;
    $whereParts[] = "c.phone LIKE ?";
    $searchParams[] = $like;
    $whereParts[] = "CAST(c.inn AS CHAR) LIKE ?";
    $searchParams[] = $like;

    if ($queryDigits !== '' && strlen($queryDigits) >= 4) {
      $whereParts[] = "CAST(c.inn AS CHAR) LIKE ?";
      $searchParams[] = $queryDigits . '%';
    }
    if ($queryDigits !== '' && strlen($queryDigits) >= 4) {
      $phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
      $whereParts[] = $phoneExpr . " LIKE ?";
      $searchParams[] = '%' . $queryDigits . '%';
    }
  }

  if (!$whereParts) return [];

  /**
   * $whereSql - финальное условие поиска.
   */
  $whereSql = '(' . implode("\n      OR ", $whereParts) . ')';
  /**
   * $rows - сырые строки из БД.
   */
  $rows = [];

  if (clients_search_can_manage($roles)) {
    $sql = "
      SELECT
        c.id,
        c.first_name,
        c.last_name,
        c.middle_name,
        c.phone,
        c.email,
        c.inn,
        MAX(r.visit_at) AS last_visit_at
      FROM " . CLIENTS_TABLE . " c
      LEFT JOIN " . CLIENTS_REQUESTS_TABLE . " r
        ON r.client_id = c.id
       AND r.archived_at IS NULL
      WHERE " . $whereSql . "
      GROUP BY c.id, c.first_name, c.last_name, c.middle_name, c.phone, c.email, c.inn
      ORDER BY (MAX(r.visit_at) IS NULL), MAX(r.visit_at) DESC, c.id DESC
      LIMIT " . $limitSafe . "
    ";

    $st = $pdo->prepare($sql);
    $st->execute($searchParams);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } elseif (clients_search_can_limited($roles) && $userId > 0) {
    $sql = "
      SELECT
        c.id,
        c.first_name,
        c.last_name,
        c.middle_name,
        c.phone,
        c.email,
        c.inn,
        MAX(r.visit_at) AS last_visit_at
      FROM " . CLIENTS_REQUESTS_TABLE . " r
      JOIN " . CLIENTS_TABLE . " c ON c.id = r.client_id
      WHERE r.archived_at IS NULL
        AND r.client_id IS NOT NULL
        AND r.specialist_user_id = ?
        AND r.status IN ('confirmed', 'in_work')
        AND " . $whereSql . "
      GROUP BY c.id, c.first_name, c.last_name, c.middle_name, c.phone, c.email, c.inn
      ORDER BY MAX(r.visit_at) DESC, c.id DESC
      LIMIT " . $limitSafe . "
    ";

    $params = array_merge([(int)$userId], $searchParams);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } else {
    return [];
  }

  /**
   * $items - нормализованный ответ.
   */
  $items = [];
  foreach ($rows as $r) {
    /**
     * $id - ID клиента.
     */
    $id = (int)($r['id'] ?? 0);
    if ($id <= 0) continue;

    /**
     * $first/$last/$middle - ФИО по частям.
     */
    $first = trim((string)($r['first_name'] ?? ''));
    $last = trim((string)($r['last_name'] ?? ''));
    $middle = trim((string)($r['middle_name'] ?? ''));
    /**
     * $phone/$email/$inn - контакты и ИНН.
     */
    $phone = trim((string)($r['phone'] ?? ''));
    $email = trim((string)($r['email'] ?? ''));
    $inn = trim((string)($r['inn'] ?? ''));
    /**
     * $lastVisitAt - дата последнего визита.
     */
    $lastVisitAt = trim((string)($r['last_visit_at'] ?? ''));

    /**
     * $displayName - отображаемое ФИО.
     */
    $displayName = trim($last . ' ' . $first . ' ' . $middle);
    if ($displayName === '') {
      $displayName = $first !== '' ? $first : clients_t('clients.client_fallback', ['id' => $id]);
    }

    /**
     * $labelParts - части подписи для списков выбора.
     */
    $labelParts = [$displayName];
    if ($phone !== '') $labelParts[] = $phone;
    if ($inn !== '') $labelParts[] = clients_t('clients.inn_prefix') . ' ' . $inn;

    $items[] = [
      'id' => $id,
      'first_name' => $first,
      'last_name' => $last,
      'middle_name' => $middle,
      'display_name' => $displayName,
      'phone' => $phone,
      'email' => $email,
      'inn' => $inn,
      'last_visit_at' => $lastVisitAt,
      'label' => implode(' | ', $labelParts),
    ];
  }

  return $items;
}
