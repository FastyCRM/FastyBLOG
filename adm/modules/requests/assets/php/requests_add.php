<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_add.php
 * ROLE: add — создание заявки из CRM
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/requests_lib.php';

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

if (!$isAdmin && !$isManager) {
  audit_log('requests', 'create', 'warn', [
    'reason' => 'deny',
  ], 'request', null, $uid, $actorRole);
  flash('Доступ запрещён', 'danger', 1);
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $pdo — БД
 */
$pdo = db();

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
 * $clientName — имя клиента
 */
$clientName = trim((string)($_POST['client_name'] ?? ''));
/**
 * $clientPhoneRaw — телефон (сырой)
 */
$clientPhoneRaw = trim((string)($_POST['client_phone'] ?? ''));
/**
 * $clientEmail — email
 */
$clientEmail = trim((string)($_POST['client_email'] ?? ''));
$selectedClientId = (int)($_POST['client_id'] ?? 0);
/**
 * $serviceId — услуга
 */
$serviceId = (int)($_POST['service_id'] ?? 0);
/**
 * $specialistId — специалист
 */
$specialistId = (int)($_POST['specialist_user_id'] ?? 0);
/**
 * $visitDate — дата визита
 */
$visitDate = trim((string)($_POST['visit_date'] ?? ''));
/**
 * $visitTime — время визита
 */
$visitTime = trim((string)($_POST['visit_time'] ?? ''));
/**
 * $durationMin — длительность (мин)
 */
$durationMin = (int)($_POST['duration_min'] ?? 0);
/**
 * $priceTotal — стоимость
 */
$priceTotal = (int)($_POST['price_total'] ?? 0);

/**
 * $clientPhone — нормализованный телефон
 */
$clientPhone = requests_norm_phone($clientPhoneRaw);
/**
 * $phoneLen — длина телефона
 */
$phoneLen = strlen($clientPhone);



/**
 * Нормализуем длительность
 */
if ($durationMin <= 0) {
  $durationMin = $serviceId > 0 ? requests_service_duration($pdo, $serviceId) : 30;
}

try {
  /**
   * $tmpPass — временный пароль (если создаём клиента)
   */
  $tmpPass = null;

  /**
   * $clientId — id клиента
   */
  $clientId = 0;

  if ($selectedClientId > 0) {
    $stClient = $pdo->prepare("\n      SELECT id, first_name, last_name, middle_name, phone, email\n      FROM clients\n      WHERE id = :id\n      LIMIT 1\n    ");
    $stClient->execute([':id' => $selectedClientId]);
    $clientRow = $stClient->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$clientRow) {
      audit_log('requests', 'create', 'warn', [
        'reason' => 'selected_client_not_found',
        'selected_client_id' => $selectedClientId,
      ], 'request', null, $uid, $actorRole);
      flash('Выбранный клиент не найден', 'warn');
      redirect_return('/adm/index.php?m=requests');
    }

    $first = trim((string)($clientRow['first_name'] ?? ''));
    $last = trim((string)($clientRow['last_name'] ?? ''));
    $middle = trim((string)($clientRow['middle_name'] ?? ''));

    $clientName = trim($last . ' ' . $first . ' ' . $middle);
    if ($clientName === '') {
      $clientName = $first !== '' ? $first : ('Клиент #' . $selectedClientId);
    }

    $clientPhone = requests_norm_phone((string)($clientRow['phone'] ?? ''));
    $phoneLen = strlen($clientPhone);
    $clientEmail = trim((string)($clientRow['email'] ?? ''));

    if ($clientPhone === '' || $phoneLen !== 11) {
      audit_log('requests', 'create', 'warn', [
        'reason' => 'selected_client_invalid_phone',
        'selected_client_id' => $selectedClientId,
        'phone' => ($clientPhone !== '' ? $clientPhone : null),
        'phone_len' => $phoneLen,
      ], 'request', null, $uid, $actorRole);
      flash('У выбранного клиента нет корректного телефона', 'warn');
      redirect_return('/adm/index.php?m=requests');
    }

    $clientId = $selectedClientId;
  } else {
    if ($clientName === '' || $clientPhone === '' || $phoneLen !== 11) {
      audit_log('requests', 'create', 'warn', [
        'reason' => 'validation',
        'name' => ($clientName !== ''),
        'phone' => ($clientPhone !== '' ? $clientPhone : null),
        'phone_len' => $phoneLen,
      ], 'request', null, $uid, $actorRole);

      flash('Имя и телефон обязательны', 'warn');
      redirect_return('/adm/index.php?m=requests');
    }

    $clientId = requests_find_or_create_client($pdo, $clientName, $clientPhone, $clientEmail, $tmpPass);
  }

  /**
   * $visitAt — дата/время визита
   */
  $visitAt = null;
  if ($visitDate !== '' && $visitTime !== '') {
    $visitAt = $visitDate . ' ' . $visitTime . ':00';
  }

  if ($useSpecialists && $useTimeSlots && $specialistId > 0) {
    if ($visitDate === '' || $visitTime === '' || $visitAt === null) {
      audit_log('requests', 'create', 'warn', [
        'reason' => 'visit_required',
        'specialist_id' => $specialistId,
      ], 'request', null, $uid, $actorRole);
      flash('Выберите дату и время записи', 'warn');
      redirect_return('/adm/index.php?m=requests');
    }

    /**
     * $slotCheck — проверка доступности интервала
     */
    $slotCheck = requests_slot_validate($pdo, $specialistId, $visitDate, $visitTime, $durationMin, null);
    if (empty($slotCheck['ok'])) {
      audit_log('requests', 'create', 'warn', [
        'reason' => 'slot_unavailable',
        'specialist_id' => $specialistId,
        'visit_date' => $visitDate,
        'visit_time' => $visitTime,
        'duration_min' => $durationMin,
        'slot_reason' => (string)($slotCheck['reason'] ?? ''),
      ], 'request', null, $uid, $actorRole);

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

  $pdo->prepare("\n    INSERT INTO " . REQUESTS_TABLE . "\n      (status, source, client_id, client_name, client_phone, client_email,\n       service_id, specialist_user_id, visit_at, slot_key, duration_min, price_total, created_by, created_at, updated_at)\n    VALUES\n      (:status, :source, :client_id, :client_name, :client_phone, :client_email,\n       :service_id, :specialist_user_id, :visit_at, :slot_key, :duration_min, :price_total, :created_by, NOW(), NOW())\n  ")->execute([
    ':status' => REQUESTS_STATUS_NEW,
    ':source' => 'crm',
    ':client_id' => $clientId > 0 ? $clientId : null,
    ':client_name' => $clientName,
    ':client_phone' => $clientPhone,
    ':client_email' => ($clientEmail !== '' ? $clientEmail : null),
    ':service_id' => ($useSpecialists && $serviceId > 0) ? $serviceId : null,
    ':specialist_user_id' => ($useSpecialists && $specialistId > 0) ? $specialistId : null,
    ':visit_at' => $visitAt,
    ':slot_key' => $slotKey,
    ':duration_min' => $durationMin,
    ':price_total' => ($priceTotal > 0 ? $priceTotal : null),
    ':created_by' => $uid > 0 ? $uid : null,
  ]);

  /**
   * $requestId — id заявки
   */
  $requestId = (int)$pdo->lastInsertId();

  requests_add_history($pdo, $requestId, $uid, 'create', null, REQUESTS_STATUS_NEW, [
    'source' => 'crm',
  ]);

  audit_log('requests', 'create', 'info', [
    'id' => $requestId,
    'client_id' => $clientId,
    'selected_client_id' => ($selectedClientId > 0 ? $selectedClientId : null),
    'phone' => $clientPhone,
  ], 'request', $requestId, $uid, $actorRole);

  /**
   * $notifyResult — результат TG-уведомлений по новой заявке.
   */
  $notifyResult = requests_tg_notify_status($pdo, $requestId, REQUESTS_STATUS_NEW, [
    'actor_user_id' => $uid,
    'actor_role' => $actorRole,
    'action' => 'create',
  ]);
  if (($notifyResult['ok'] ?? false) !== true) {
    audit_log('requests', 'tg_notify', 'warn', [
      'request_id' => $requestId,
      'status_to' => REQUESTS_STATUS_NEW,
      'reason' => (string)($notifyResult['reason'] ?? ''),
    ], 'request', $requestId, $uid, $actorRole);
  }

  flash('Заявка создана', 'ok');
} catch (Throwable $e) {
  if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
    audit_log('requests', 'create', 'warn', [
      'error' => 'duplicate_slot',
      'visit_at' => $visitAt,
      'specialist_id' => ($useSpecialists && $specialistId > 0) ? $specialistId : null,
    ], 'request', null, $uid, $actorRole);
    flash('Это время уже занято', 'warn');
    redirect_return('/adm/index.php?m=requests');
  }

  audit_log('requests', 'create', 'error', [
    'error' => $e->getMessage(),
  ], 'request', null, $uid, $actorRole);

  flash('Ошибка создания заявки', 'danger', 1);
}

redirect_return('/adm/index.php?m=requests');
