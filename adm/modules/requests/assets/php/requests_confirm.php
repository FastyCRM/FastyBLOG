<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_confirm.php
 * ROLE: confirm — подтверждение заявки
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
 * $isSpecialist — специалист
 */
$isSpecialist = requests_is_specialist($roles);

/**
 * $actorRole — роль
 */
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

/**
 * $settings — настройки
 */
$settings = requests_settings_get($pdo);

/**
 * $useSpecialists — режим специалистов
 */
$useSpecialists = ((int)($settings['use_specialists'] ?? 0) === 1);
/**
 * $useTimeSlots — интервальный режим
 */
$useTimeSlots = ($useSpecialists && ((int)($settings['use_time_slots'] ?? 0) === 1));

/**
 * $id — заявка
 */
$id = (int)($_POST['id'] ?? 0);
/**
 * $visitDate — дата визита
 */
$visitDate = trim((string)($_POST['visit_date'] ?? ''));
/**
 * $visitTime — время визита
 */
$visitTime = trim((string)($_POST['visit_time'] ?? ''));
/**
 * $durationMin — длительность
 */
$durationMin = (int)($_POST['duration_min'] ?? 0);
/**
 * $priceTotal — стоимость
 */
$priceTotal = (int)($_POST['price_total'] ?? 0);
/**
 * $serviceId — услуга
 */
$serviceId = (int)($_POST['service_id'] ?? 0);
/**
 * $specialistId — специалист
 */
$specialistId = (int)($_POST['specialist_user_id'] ?? 0);
/**
 * $comment — комментарий
 */
$comment = trim((string)($_POST['comment'] ?? ''));

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
  audit_log('requests', 'confirm', 'warn', [
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
$st = $pdo->prepare("SELECT status, client_id, client_name, client_phone, client_email, specialist_user_id, visit_at FROM " . REQUESTS_TABLE . " WHERE id = :id LIMIT 1");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  audit_log('requests', 'confirm', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);
  flash('Заявка не найдена', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * Проверка прав подтверждения
 */
if (!$isAdmin && !$isManager) {
  if (!$useSpecialists) {
    audit_log('requests', 'confirm', 'warn', [
      'reason' => 'deny',
      'id' => $id,
    ], 'request', $id, $uid, $actorRole);
    flash('Доступ запрещен', 'danger', 1);
    redirect_return('/adm/index.php?m=requests');
  }

  $specId = (int)($row['specialist_user_id'] ?? 0);
  if (!$isSpecialist || $specId <= 0 || $specId !== $uid) {
    audit_log('requests', 'confirm', 'warn', [
      'reason' => 'deny',
      'id' => $id,
      'spec_id' => $specId,
    ], 'request', $id, $uid, $actorRole);
    flash('Доступ запрещен', 'danger', 1);
    redirect_return('/adm/index.php?m=requests');
  }
}

/**
 * $status — статус
 */
$status = (string)($row['status'] ?? '');
$existingVisitAt = (string)($row['visit_at'] ?? '');
$clientId = (int)($row['client_id'] ?? 0);
$clientName = (string)($row['client_name'] ?? '');
$clientPhone = (string)($row['client_phone'] ?? '');
$clientEmail = (string)($row['client_email'] ?? '');

$serviceName = '';
$servicePrice = 0;
if ($serviceId > 0) {
  $stSrv = $pdo->prepare("SELECT name, price FROM " . REQUESTS_SERVICES_TABLE . " WHERE id = :id LIMIT 1");
  $stSrv->execute([':id' => $serviceId]);
  $srvRow = $stSrv->fetch(PDO::FETCH_ASSOC);
  if ($srvRow) {
    $serviceName = (string)($srvRow['name'] ?? '');
    $servicePrice = (int)($srvRow['price'] ?? 0);
  }
}

$specialistName = '';
if ($specialistId > 0) {
  $stSpec = $pdo->prepare("SELECT name FROM users WHERE id = :id LIMIT 1");
  $stSpec->execute([':id' => $specialistId]);
  $specialistName = (string)($stSpec->fetchColumn() ?: '');
}

if (!$items && $serviceId > 0 && $serviceName !== '') {
  $items[] = [
    'service_id' => $serviceId,
    'service_name' => $serviceName,
    'qty' => 1,
    'price' => $servicePrice,
    'total' => $servicePrice,
  ];
  $sumItems = $servicePrice;
}

/**
 * $durationMin — длительность по услугам
 */
$durationMin = requests_items_duration($pdo, $items);

if ($status !== REQUESTS_STATUS_NEW) {
  audit_log('requests', 'confirm', 'warn', [
    'reason' => 'status_invalid',
    'id' => $id,
    'status' => $status,
  ], 'request', $id, $uid, $actorRole);
  flash('Статус заявки недопустим', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

if ($useSpecialists && $serviceId <= 0) {
  audit_log('requests', 'confirm', 'warn', [
    'reason' => 'service_required',
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);
  flash('Выберите услугу', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

if ($useSpecialists && $specialistId <= 0) {
  audit_log('requests', 'confirm', 'warn', [
    'reason' => 'specialist_required',
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);
  flash('Выберите специалиста', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

if ($useSpecialists && $serviceId > 0 && $specialistId > 0) {
  /**
   * $stLink — связь специалист-услуга
   */
  $stLink = $pdo->prepare("SELECT 1 FROM " . REQUESTS_USER_SERVICES_TABLE . " WHERE service_id = :sid AND user_id = :uid LIMIT 1");
  $stLink->execute([':sid' => $serviceId, ':uid' => $specialistId]);
  if (!$stLink->fetchColumn()) {
    audit_log('requests', 'confirm', 'warn', [
      'reason' => 'specialist_service_mismatch',
      'id' => $id,
      'service_id' => $serviceId,
      'specialist_id' => $specialistId,
    ], 'request', $id, $uid, $actorRole);
    flash('Специалист не привязан к услуге', 'warn');
    redirect_return('/adm/index.php?m=requests');
  }
}

/**
 * $visitAt — дата/время визита
 */
$visitAt = null;
if ($visitDate !== '' && $visitTime !== '') {
  $visitAt = $visitDate . ' ' . $visitTime . ':00';
} elseif ($existingVisitAt !== '') {
  $visitAt = $existingVisitAt;
}

if ($useSpecialists && $useTimeSlots && $specialistId > 0) {
  /**
   * $slotDate — дата для проверки слота
   */
  $slotDate = '';
  /**
   * $slotTime — время для проверки слота
   */
  $slotTime = '';

  if ($visitAt !== null && $visitAt !== '') {
    $slotDate = (string)date('Y-m-d', strtotime($visitAt));
    $slotTime = (string)date('H:i', strtotime($visitAt));
  }

  if ($slotDate === '' || $slotTime === '') {
    audit_log('requests', 'confirm', 'warn', [
      'reason' => 'visit_required',
      'id' => $id,
      'specialist_id' => $specialistId,
    ], 'request', $id, $uid, $actorRole);
    flash('Выберите дату и время записи', 'warn');
    redirect_return('/adm/index.php?m=requests');
  }

  /**
   * $slotCheck — проверка доступности интервала
   */
  $slotCheck = requests_slot_validate($pdo, $specialistId, $slotDate, $slotTime, $durationMin, $id);
  if (empty($slotCheck['ok'])) {
    audit_log('requests', 'confirm', 'warn', [
      'reason' => 'slot_unavailable',
      'id' => $id,
      'specialist_id' => $specialistId,
      'visit_date' => $slotDate,
      'visit_time' => $slotTime,
      'duration_min' => $durationMin,
      'slot_reason' => (string)($slotCheck['reason'] ?? ''),
    ], 'request', $id, $uid, $actorRole);

    $slotMsg = trim((string)($slotCheck['message'] ?? ''));
    if ($slotMsg === '') {
      $slotMsg = 'Это время недоступно';
    }
    flash($slotMsg, 'warn');
    redirect_return('/adm/index.php?m=requests');
  }
}

/**
 * $slotKey — ключ слота (защита от дублей)
 */
$slotKey = null;
if ($useSpecialists && $specialistId > 0 && $visitAt !== null) {
  $slotKey = requests_slot_key($specialistId, $visitAt);
}

if ($sumItems > 0) {
  $priceTotal = $sumItems;
}

try {
  /**
   * Если в форме подтверждения добавлены свободные услуги:
   * создаём их в справочнике services и привязываем к "Без категории".
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

      $serviceNameLocal = trim((string)($it['service_name'] ?? ''));
      if ($serviceNameLocal === '') {
        continue;
      }

      $stFindService->execute([$serviceNameLocal]);
      $sid = (int)($stFindService->fetchColumn() ?: 0);

      if ($sid <= 0) {
        if ($uncategorizedId <= 0) {
          $uncategorizedId = services_get_uncategorized_id($pdo, true);
        }

        $priceVal = (int)($it['price'] ?? 0);
        if ($priceVal < 0) $priceVal = 0;

        $stInsertService->execute([
          ':name' => $serviceNameLocal,
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

  $pdo->prepare("\n    UPDATE " . REQUESTS_TABLE . "\n    SET
      status = :status,
      confirmed_by = :uid,
      confirmed_at = NOW(),
      visit_at = :visit_at,
      slot_key = :slot_key,
      duration_min = :duration_min,
      price_total = :price_total,
      service_id = :service_id,
      specialist_user_id = :specialist_user_id,
      updated_at = NOW()
    WHERE id = :id
    LIMIT 1
  ")->execute([
    ':status' => REQUESTS_STATUS_CONFIRMED,
    ':uid' => $uid,
    ':visit_at' => $visitAt,
    ':slot_key' => $slotKey,
    ':duration_min' => $durationMin,
    ':price_total' => ($priceTotal > 0 ? $priceTotal : null),
    ':service_id' => ($useSpecialists && $serviceId > 0) ? $serviceId : null,
    ':specialist_user_id' => ($useSpecialists && $specialistId > 0) ? $specialistId : null,
    ':id' => $id,
  ]);

  if ($items) {
    $totalSum = $sumItems > 0 ? $sumItems : 0;
    $invoiceId = 0;
    $stInv = $pdo->prepare("
      SELECT id
      FROM " . REQUESTS_INVOICES_TABLE . "
      WHERE request_id = :rid
      ORDER BY created_at DESC, id DESC
      LIMIT 1
    ");
    $stInv->execute([':rid' => $id]);
    $invoiceId = (int)($stInv->fetchColumn() ?: 0);

    if ($invoiceId > 0) {
      $pdo->prepare("
        UPDATE " . REQUESTS_INVOICES_TABLE . "
        SET
          total = :total,
          client_id = :client_id,
          client_name = :client_name,
          client_phone = :client_phone,
          client_email = :client_email,
          specialist_user_id = :spec_id,
          specialist_name = :spec_name,
          updated_at = NOW()
        WHERE id = :id
        LIMIT 1
      ")->execute([
        ':total' => $totalSum,
        ':client_id' => $clientId > 0 ? $clientId : null,
        ':client_name' => $clientName !== '' ? $clientName : null,
        ':client_phone' => $clientPhone !== '' ? $clientPhone : null,
        ':client_email' => $clientEmail !== '' ? $clientEmail : null,
        ':spec_id' => $specialistId > 0 ? $specialistId : null,
        ':spec_name' => $specialistName !== '' ? $specialistName : null,
        ':id' => $invoiceId,
      ]);

      $pdo->prepare("DELETE FROM " . REQUESTS_INVOICE_ITEMS_TABLE . " WHERE invoice_id = :iid")
        ->execute([':iid' => $invoiceId]);
    } else {
      $pdo->prepare("
        INSERT INTO " . REQUESTS_INVOICES_TABLE . " (
          request_id,
          client_id,
          client_name,
          client_phone,
          client_email,
          specialist_user_id,
          specialist_name,
          total,
          created_at
        ) VALUES (
          :request_id,
          :client_id,
          :client_name,
          :client_phone,
          :client_email,
          :spec_id,
          :spec_name,
          :total,
          NOW()
        )
      ")->execute([
        ':request_id' => $id,
        ':client_id' => $clientId > 0 ? $clientId : null,
        ':client_name' => $clientName !== '' ? $clientName : null,
        ':client_phone' => $clientPhone !== '' ? $clientPhone : null,
        ':client_email' => $clientEmail !== '' ? $clientEmail : null,
        ':spec_id' => $specialistId > 0 ? $specialistId : null,
        ':spec_name' => $specialistName !== '' ? $specialistName : null,
        ':total' => $totalSum,
      ]);
      $invoiceId = (int)$pdo->lastInsertId();
    }

    if ($invoiceId > 0) {
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

  if ($comment !== '') {
    /**
     * $clientId — id клиента
     */
    $clientId = (int)($row['client_id'] ?? 0);
    requests_add_comment($pdo, $id, $uid, $clientId > 0 ? $clientId : null, 'user', $comment);
  }

  requests_add_history($pdo, $id, $uid, 'confirm', REQUESTS_STATUS_NEW, REQUESTS_STATUS_CONFIRMED, [
    'visit_at' => $visitAt,
  ]);

  audit_log('requests', 'confirm', 'info', [
    'id' => $id,
    'visit_at' => $visitAt,
  ], 'request', $id, $uid, $actorRole);

  /**
   * $notifyResult — результат TG-уведомлений по подтверждению.
   */
  $notifyResult = requests_tg_notify_status($pdo, $id, REQUESTS_STATUS_CONFIRMED, [
    'actor_user_id' => $uid,
    'actor_role' => $actorRole,
    'status_from' => REQUESTS_STATUS_NEW,
    'action' => 'confirm',
  ]);
  if (($notifyResult['ok'] ?? false) !== true) {
    audit_log('requests', 'tg_notify', 'warn', [
      'request_id' => $id,
      'status_to' => REQUESTS_STATUS_CONFIRMED,
      'reason' => (string)($notifyResult['reason'] ?? ''),
    ], 'request', $id, $uid, $actorRole);
  }

  flash('Заявка подтверждена', 'ok');
} catch (Throwable $e) {
  if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
    audit_log('requests', 'confirm', 'warn', [
      'error' => 'duplicate_slot',
      'id' => $id,
      'visit_at' => $visitAt,
      'specialist_id' => ($useSpecialists && $specialistId > 0) ? $specialistId : null,
    ], 'request', $id, $uid, $actorRole);
    flash('Это время уже занято', 'warn');
    redirect_return('/adm/index.php?m=requests');
  }

  audit_log('requests', 'confirm', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'request', $id, $uid, $actorRole);

  flash('Ошибка подтверждения заявки', 'danger', 1);
}

redirect_return('/adm/index.php?m=requests');
