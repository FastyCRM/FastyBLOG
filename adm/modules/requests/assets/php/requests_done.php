<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_done.php
 * ROLE: done — завершение заявки
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/requests_lib.php';
require_once __DIR__ . '/../../../services/settings.php';
require_once __DIR__ . '/../../../services/assets/php/services_lib.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('requests'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $uid — пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $roles — роли
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

/**
 * $isAdmin — админ
 */
$isAdmin = requests_is_admin($roles);

/**
 * $isManager — менеджер
 */
$isManager = requests_is_manager($roles);

/**
 * $actorRole — роль
 */
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

/**
 * $id — заявка
 */
$id = (int)($_POST['id'] ?? 0);
/**
 * $priceTotal — стоимость выполнения
 */
$priceTotal = (int)($_POST['price_total'] ?? 0);
if ($priceTotal < 0) $priceTotal = 0;
$invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
$actNumber = trim((string)($_POST['act_number'] ?? ''));
$contractNumber = trim((string)($_POST['contract_number'] ?? ''));
$invoiceDate = trim((string)($_POST['invoice_date'] ?? ''));
if ($invoiceDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
  $invoiceDate = null;
}

$itemServiceIds = $_POST['item_service_id'] ?? [];
$itemNames = $_POST['item_name'] ?? [];
$itemQtys = $_POST['item_qty'] ?? [];
$itemPrices = $_POST['item_price'] ?? [];
$items = [];
$sumItems = 0;

if (is_array($itemServiceIds) || is_array($itemNames) || is_array($itemQtys) || is_array($itemPrices)) {
  $count = max(
    is_array($itemServiceIds) ? count($itemServiceIds) : 0,
    is_array($itemNames) ? count($itemNames) : 0,
    is_array($itemQtys) ? count($itemQtys) : 0,
    is_array($itemPrices) ? count($itemPrices) : 0
  );

  for ($i = 0; $i < $count; $i++) {
    $sid = (int)($itemServiceIds[$i] ?? 0);
    $name = trim((string)($itemNames[$i] ?? ''));
    $qty = (int)($itemQtys[$i] ?? 1);
    $price = (int)($itemPrices[$i] ?? 0);
    if ($qty <= 0) $qty = 1;
    if ($price < 0) $price = 0;

    if ($name === '' && $sid <= 0) continue;
    if ($name === '') $name = '#' . $sid;

    $total = $qty * $price;
    $sumItems += $total;
    $items[] = [
      'service_id' => $sid > 0 ? $sid : null,
      'service_name' => $name,
      'qty' => $qty,
      'price' => $price,
      'total' => $total,
    ];
  }
}
if ($id <= 0) {
  audit_log('requests', 'done', 'warn', [
    'reason' => 'validation',
    'id' => ($id > 0 ? $id : null),
  ], 'request', null, $uid, $actorRole);
  flash('Некорректная заявка', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $row — заявка
 */
/**
 * $st — запрос
 */
$st = $pdo->prepare("
  SELECT
    status,
    taken_by,
    client_id,
    client_name,
    client_phone,
    client_email,
    specialist_user_id
  FROM " . REQUESTS_TABLE . "
  WHERE id = :id
  LIMIT 1
");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  audit_log('requests', 'done', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);
  flash('Заявка не найдена', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * Existing invoice (draft or previous)
 */
$existingInvoice = null;
$existingItems = [];
try {
  $stInv = $pdo->prepare("
    SELECT *
    FROM " . REQUESTS_INVOICES_TABLE . "
    WHERE request_id = :rid
    ORDER BY created_at DESC, id DESC
    LIMIT 1
  ");
  $stInv->execute([':rid' => $id]);
  $existingInvoice = $stInv->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($existingInvoice) {
    $stItems = $pdo->prepare("
      SELECT service_id, service_name, qty, price, total
      FROM " . REQUESTS_INVOICE_ITEMS_TABLE . "
      WHERE invoice_id = :iid
      ORDER BY id ASC
    ");
    $stItems->execute([':iid' => (int)$existingInvoice['id']]);
    $existingItems = $stItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) {
  $existingInvoice = null;
  $existingItems = [];
}

/**
 * $status — статус
 */
$status = (string)($row['status'] ?? '');

if ($status !== REQUESTS_STATUS_IN_WORK) {
  audit_log('requests', 'done', 'warn', [
    'reason' => 'status_invalid',
    'id' => $id,
    'status' => $status,
  ], 'request', $id, $uid, $actorRole);
  flash('Заявка не в работе', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $takenBy — кто принял
 */
$takenBy = (int)($row['taken_by'] ?? 0);

if (!$isAdmin && !$isManager && $takenBy !== $uid) {
  audit_log('requests', 'done', 'warn', [
    'reason' => 'deny',
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);
  flash('Нет доступа', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * Данные для счета
 */
$clientId = (int)($row['client_id'] ?? 0);
$clientName = (string)($row['client_name'] ?? '');
$clientPhone = (string)($row['client_phone'] ?? '');
$clientEmail = (string)($row['client_email'] ?? '');
$specialistId = (int)($row['specialist_user_id'] ?? 0);

$specialistName = '';
if ($specialistId > 0) {
  $stName = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
  $stName->execute([$specialistId]);
  $specialistName = (string)($stName->fetchColumn() ?: '');
}

$executorName = '';
if ($uid > 0) {
  $stExec = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
  $stExec->execute([$uid]);
  $executorName = (string)($stExec->fetchColumn() ?: '');
}

$existingTotal = $existingInvoice ? (int)($existingInvoice['total'] ?? 0) : 0;
if (!$items && $existingItems) {
  $items = $existingItems;
  $sumItems = 0;
  foreach ($existingItems as $it) {
    $sumItems += (int)($it['total'] ?? 0);
  }
}
if ($sumItems <= 0 && $existingTotal > 0) {
  $sumItems = $existingTotal;
}

/**
 * $durationMin — длительность по услугам
 */
$durationMin = requests_items_duration($pdo, $items);

$existingInvoiceNumber = $existingInvoice ? (string)($existingInvoice['invoice_number'] ?? '') : '';
$existingActNumber = $existingInvoice ? (string)($existingInvoice['act_number'] ?? '') : '';
$existingContractNumber = $existingInvoice ? (string)($existingInvoice['contract_number'] ?? '') : '';
$existingInvoiceDate = $existingInvoice ? (string)($existingInvoice['invoice_date'] ?? '') : '';

if ($invoiceNumber === '' && $existingInvoiceNumber !== '') $invoiceNumber = $existingInvoiceNumber;
if ($actNumber === '' && $existingActNumber !== '') $actNumber = $existingActNumber;
if ($contractNumber === '' && $existingContractNumber !== '') $contractNumber = $existingContractNumber;
if ($invoiceDate === null && $existingInvoiceDate !== '') $invoiceDate = $existingInvoiceDate;

$totalSum = $sumItems > 0 ? $sumItems : $priceTotal;
$hasInvoice = ($items || $invoiceNumber !== '' || $actNumber !== '' || $contractNumber !== '' || $invoiceDate !== null || $totalSum > 0);

if ($hasInvoice) {
  $autoStamp = date('Ymd');
  if ($invoiceDate === null) {
    $invoiceDate = date('Y-m-d');
  }
  if ($invoiceNumber === '') {
    $invoiceNumber = 'INV-' . $id . '-' . $autoStamp;
  }
  if ($actNumber === '') {
    $actNumber = 'ACT-' . $id . '-' . $autoStamp;
  }
  if ($contractNumber === '') {
    $contractNumber = 'CTR-' . $id . '-' . $autoStamp;
  }
}

try {
  $pdo->beginTransaction();

  /**
   * Если услуги добавлены вручную — создаём их в списке и привязываем к "Без категории"
   */
  $uncategorizedId = 0;
  if ($items) {
    $stFindService = $pdo->prepare("SELECT id FROM " . SERVICES_TABLE . " WHERE name = ? LIMIT 1");
    $stInsertService = $pdo->prepare("
      INSERT INTO " . SERVICES_TABLE . " (name, price, status, created_at)
      VALUES (:name, :price, 'active', NOW())
    ");
    $stLinkService = $pdo->prepare("
      INSERT INTO " . SERVICES_SPECIALTY_SERVICES_TABLE . " (specialty_id, service_id)
      VALUES (:specialty_id, :service_id)
    ");

    foreach ($items as &$it) {
      if (!empty($it['service_id'])) {
        continue;
      }

      $serviceName = trim((string)($it['service_name'] ?? ''));
      if ($serviceName === '') {
        continue;
      }

      $stFindService->execute([$serviceName]);
      $sid = (int)($stFindService->fetchColumn() ?: 0);

      if ($sid <= 0) {
        if ($uncategorizedId <= 0) {
          $uncategorizedId = services_get_uncategorized_id($pdo, true);
        }

        $priceVal = (int)($it['price'] ?? 0);
        if ($priceVal < 0) $priceVal = 0;

        $stInsertService->execute([
          ':name' => $serviceName,
          ':price' => ($priceVal > 0 ? $priceVal : null),
        ]);
        $sid = (int)$pdo->lastInsertId();

        if ($sid > 0 && $uncategorizedId > 0) {
          $stLinkService->execute([
            ':specialty_id' => $uncategorizedId,
            ':service_id' => $sid,
          ]);
        }
      }

      if ($sid > 0) {
        $it['service_id'] = $sid;
      }
    }
    unset($it);

    if ($uncategorizedId > 0) {
      services_refresh_uncategorized($pdo);
    }
  }
  $sql = "\n    UPDATE " . REQUESTS_TABLE . "\n    SET
      status = :status,
      done_by = :uid,
      done_at = NOW(),
      duration_min = :duration_min,
      updated_at = NOW()
  ";
  $params = [
    ':status' => REQUESTS_STATUS_DONE,
    ':uid' => $uid,
    ':duration_min' => $durationMin,
    ':id' => $id,
  ];

  if ($totalSum > 0) {
    $sql .= ", price_total = :price_total";
    $params[':price_total'] = $totalSum;
  }

  $sql .= " WHERE id = :id LIMIT 1";

  $pdo->prepare($sql)->execute($params);

  $invoiceId = null;
  if ($hasInvoice) {
    if ($existingInvoice) {
      $invoiceId = (int)$existingInvoice['id'];
      $pdo->prepare("
        UPDATE " . REQUESTS_INVOICES_TABLE . "
        SET
          invoice_number = :invoice_number,
          act_number = :act_number,
          contract_number = :contract_number,
          invoice_date = :invoice_date,
          client_id = :client_id,
          client_name = :client_name,
          client_phone = :client_phone,
          client_email = :client_email,
          executor_user_id = :executor_user_id,
          executor_name = :executor_name,
          specialist_user_id = :specialist_user_id,
          specialist_name = :specialist_name,
          total = :total,
          updated_at = NOW()
        WHERE id = :id
        LIMIT 1
      ")->execute([
        ':invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
        ':act_number' => $actNumber !== '' ? $actNumber : null,
        ':contract_number' => $contractNumber !== '' ? $contractNumber : null,
        ':invoice_date' => $invoiceDate,
        ':client_id' => $clientId > 0 ? $clientId : null,
        ':client_name' => $clientName !== '' ? $clientName : null,
        ':client_phone' => $clientPhone !== '' ? $clientPhone : null,
        ':client_email' => $clientEmail !== '' ? $clientEmail : null,
        ':executor_user_id' => $uid > 0 ? $uid : null,
        ':executor_name' => $executorName !== '' ? $executorName : null,
        ':specialist_user_id' => $specialistId > 0 ? $specialistId : null,
        ':specialist_name' => $specialistName !== '' ? $specialistName : null,
        ':total' => $totalSum > 0 ? $totalSum : 0,
        ':id' => $invoiceId,
      ]);
    } else {
      $stInv = $pdo->prepare("
        INSERT INTO " . REQUESTS_INVOICES_TABLE . " (
          request_id,
          invoice_number,
          act_number,
          contract_number,
          invoice_date,
          client_id,
          client_name,
          client_phone,
          client_email,
          executor_user_id,
          executor_name,
          specialist_user_id,
          specialist_name,
          total,
          created_at
        ) VALUES (
          :request_id,
          :invoice_number,
          :act_number,
          :contract_number,
          :invoice_date,
          :client_id,
          :client_name,
          :client_phone,
          :client_email,
          :executor_user_id,
          :executor_name,
          :specialist_user_id,
          :specialist_name,
          :total,
          NOW()
        )
      ");
      $stInv->execute([
        ':request_id' => $id,
        ':invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
        ':act_number' => $actNumber !== '' ? $actNumber : null,
        ':contract_number' => $contractNumber !== '' ? $contractNumber : null,
        ':invoice_date' => $invoiceDate,
        ':client_id' => $clientId > 0 ? $clientId : null,
        ':client_name' => $clientName !== '' ? $clientName : null,
        ':client_phone' => $clientPhone !== '' ? $clientPhone : null,
        ':client_email' => $clientEmail !== '' ? $clientEmail : null,
        ':executor_user_id' => $uid > 0 ? $uid : null,
        ':executor_name' => $executorName !== '' ? $executorName : null,
        ':specialist_user_id' => $specialistId > 0 ? $specialistId : null,
        ':specialist_name' => $specialistName !== '' ? $specialistName : null,
        ':total' => $totalSum > 0 ? $totalSum : 0,
      ]);
      $invoiceId = (int)$pdo->lastInsertId();
    }

    if ($invoiceId > 0 && $items) {
      if ($existingInvoice) {
        $pdo->prepare("DELETE FROM " . REQUESTS_INVOICE_ITEMS_TABLE . " WHERE invoice_id = :iid")
          ->execute([':iid' => $invoiceId]);
      }
      $stItem = $pdo->prepare("
        INSERT INTO " . REQUESTS_INVOICE_ITEMS_TABLE . " (
          invoice_id,
          service_id,
          service_name,
          qty,
          price,
          total,
          created_at
        ) VALUES (
          :invoice_id,
          :service_id,
          :service_name,
          :qty,
          :price,
          :total,
          NOW()
        )
      ");
      foreach ($items as $it) {
        $stItem->execute([
          ':invoice_id' => $invoiceId,
          ':service_id' => $it['service_id'],
          ':service_name' => $it['service_name'],
          ':qty' => (int)$it['qty'],
          ':price' => (int)$it['price'],
          ':total' => (int)$it['total'],
        ]);
      }
    }
  }

  requests_add_history($pdo, $id, $uid, 'done', REQUESTS_STATUS_IN_WORK, REQUESTS_STATUS_DONE, [
    'price_total' => ($totalSum > 0 ? $totalSum : null),
    'invoice_id' => $invoiceId,
  ]);

  audit_log('requests', 'done', 'info', [
    'id' => $id,
    'price_total' => ($totalSum > 0 ? $totalSum : null),
    'invoice_id' => $invoiceId,
  ], 'request', $id, $uid, $actorRole);

  $pdo->commit();

  /**
   * $notifyResult — результат TG-уведомлений по выполнению.
   */
  $notifyResult = requests_tg_notify_status($pdo, $id, REQUESTS_STATUS_DONE, [
    'actor_user_id' => $uid,
    'actor_role' => $actorRole,
    'status_from' => REQUESTS_STATUS_IN_WORK,
    'action' => 'done',
  ]);
  if (($notifyResult['ok'] ?? false) !== true) {
    audit_log('requests', 'tg_notify', 'warn', [
      'request_id' => $id,
      'status_to' => REQUESTS_STATUS_DONE,
      'reason' => (string)($notifyResult['reason'] ?? ''),
    ], 'request', $id, $uid, $actorRole);
  }

  flash('Заявка выполнена', 'ok');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  audit_log('requests', 'done', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'request', $id, $uid, $actorRole);

  flash('Ошибка выполнения заявки', 'danger', 1);
}

redirect_return('/adm/index.php?m=requests');

