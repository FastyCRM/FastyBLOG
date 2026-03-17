<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_api_request_add.php
 * ROLE: api_request_add — создание заявки из внешней формы
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/requests_lib.php';
require_once ROOT_PATH . '/core/mailer.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_405('Method Not Allowed');
}

/**
 * $csrf — токен
 */
$csrf = (string)($_POST['csrf'] ?? '');
if ($csrf === '') {
  json_err('csrf_required', 403);
}
csrf_check($csrf);

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
 * $clientName — имя
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
 * $consent — согласие
 */
$consent = (int)($_POST['consent'] ?? 0);

/**
 * $clientPhone — нормализованный телефон
 */
$clientPhone = requests_norm_phone($clientPhoneRaw);
/**
 * $phoneLen — длина телефона
 */
$phoneLen = strlen($clientPhone);

if ($clientName === '' || $clientPhone === '' || $phoneLen !== 11) {
  audit_log('requests', 'create', 'warn', [
    'reason' => 'validation',
    'source' => 'landing',
    'name' => ($clientName !== ''),
    'phone' => ($clientPhone !== '' ? $clientPhone : null),
    'phone_len' => $phoneLen,
  ], 'request', null, null, null);
  json_err('Имя и телефон обязательны', 422);
}

if ($consent !== 1) {
  audit_log('requests', 'create', 'warn', [
    'reason' => 'consent_required',
    'source' => 'landing',
    'phone' => ($clientPhone !== '' ? $clientPhone : null),
  ], 'request', null, null, null);
  json_err('Нужно согласие на обработку данных', 422);
}

if ($useSpecialists) {
  if ($serviceId <= 0 || $specialistId <= 0) {
    audit_log('requests', 'create', 'warn', [
      'reason' => 'service_or_specialist_required',
      'source' => 'landing',
      'service_id' => ($serviceId > 0 ? $serviceId : null),
      'specialist_id' => ($specialistId > 0 ? $specialistId : null),
    ], 'request', null, null, null);
    json_err('Выберите услугу и специалиста', 422);
  }

  /**
   * $stCheck — проверка связи специалист-услуга
   */
  $stCheck = $pdo->prepare("SELECT 1 FROM " . REQUESTS_USER_SERVICES_TABLE . " WHERE service_id = :sid AND user_id = :uid LIMIT 1");
  $stCheck->execute([':sid' => $serviceId, ':uid' => $specialistId]);
  if (!$stCheck->fetchColumn()) {
    audit_log('requests', 'create', 'warn', [
      'reason' => 'specialist_service_mismatch',
      'source' => 'landing',
      'service_id' => $serviceId,
      'specialist_id' => $specialistId,
    ], 'request', null, null, null);
    json_err('Специалист не привязан к услуге', 422);
  }
}

/**
 * $durationMin — длительность (мин)
 */
$durationMin = $serviceId > 0 ? requests_service_duration($pdo, $serviceId) : 30;

try {
  /**
   * $tmpPass — временный пароль
   */
  $tmpPass = null;
  /**
   * $clientId — id клиента
   */
  $clientId = requests_find_or_create_client($pdo, $clientName, $clientPhone, $clientEmail, $tmpPass);

  /**
   * $visitAt — дата/время визита
   */
  $visitAt = null;
  if ($visitDate !== '' && $visitTime !== '') {
    $visitAt = $visitDate . ' ' . $visitTime . ':00';
  }

  if ($useSpecialists && $useTimeSlots) {
    if ($specialistId <= 0 || $visitDate === '' || $visitTime === '' || $visitAt === null) {
      audit_log('requests', 'create', 'warn', [
        'reason' => 'visit_required',
        'source' => 'landing',
        'specialist_id' => ($specialistId > 0 ? $specialistId : null),
      ], 'request', null, null, null);
      json_err('Выберите дату и время записи', 422);
    }

    /**
     * $slotCheck — проверка доступности интервала
     */
    $slotCheck = requests_slot_validate($pdo, $specialistId, $visitDate, $visitTime, $durationMin, null);
    if (empty($slotCheck['ok'])) {
      audit_log('requests', 'create', 'warn', [
        'reason' => 'slot_unavailable',
        'source' => 'landing',
        'specialist_id' => $specialistId,
        'visit_date' => $visitDate,
        'visit_time' => $visitTime,
        'duration_min' => $durationMin,
        'slot_reason' => (string)($slotCheck['reason'] ?? ''),
      ], 'request', null, null, null);

      $slotMsg = trim((string)($slotCheck['message'] ?? ''));
      if ($slotMsg === '') {
        $slotMsg = 'Это время недоступно';
      }
      json_err($slotMsg, 422);
    }
  }

  /**
   * $slotKey — ключ слота (защита от дублей)
   */
  $slotKey = null;
  if ($useSpecialists && $specialistId > 0 && $visitAt !== null) {
    $slotKey = requests_slot_key($specialistId, $visitAt);
  }

  $pdo->prepare("\n    INSERT INTO " . REQUESTS_TABLE . "\n      (status, source, client_id, client_name, client_phone, client_email,\n       service_id, specialist_user_id, visit_at, slot_key, duration_min,\n       consent_at, consent_ip, consent_user_agent, consent_text_version,\n       created_at, updated_at)\n    VALUES\n      (:status, :source, :client_id, :client_name, :client_phone, :client_email,\n       :service_id, :specialist_user_id, :visit_at, :slot_key, :duration_min,\n       NOW(), :ip, :ua, :consent_ver,\n       NOW(), NOW())\n  ")->execute([
    ':status' => REQUESTS_STATUS_NEW,
    ':source' => 'landing',
    ':client_id' => $clientId > 0 ? $clientId : null,
    ':client_name' => $clientName,
    ':client_phone' => $clientPhone,
    ':client_email' => ($clientEmail !== '' ? $clientEmail : null),
    ':service_id' => ($useSpecialists && $serviceId > 0) ? $serviceId : null,
    ':specialist_user_id' => ($useSpecialists && $specialistId > 0) ? $specialistId : null,
    ':visit_at' => $visitAt,
    ':slot_key' => $slotKey,
    ':duration_min' => $durationMin,
    ':ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ':ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ':consent_ver' => 'v1',
  ]);

  /**
   * $requestId — id заявки
   */
  $requestId = (int)$pdo->lastInsertId();

  requests_add_history($pdo, $requestId, null, 'create', null, REQUESTS_STATUS_NEW, [
    'source' => 'landing',
  ]);

  audit_log('requests', 'create', 'info', [
    'id' => $requestId,
    'source' => 'landing',
    'phone' => $clientPhone,
  ], 'request', $requestId, null, null);

  /**
   * $notifyResult — результат TG-уведомлений по новой заявке.
   */
  $notifyResult = requests_tg_notify_status($pdo, $requestId, REQUESTS_STATUS_NEW, [
    'actor_user_id' => 0,
    'actor_role' => '',
    'action' => 'create_api',
  ]);
  if (($notifyResult['ok'] ?? false) !== true) {
    audit_log('requests', 'tg_notify', 'warn', [
      'request_id' => $requestId,
      'status_to' => REQUESTS_STATUS_NEW,
      'reason' => (string)($notifyResult['reason'] ?? ''),
    ], 'request', $requestId, null, null);
  }

  if ($tmpPass !== null) {
    if ($clientEmail !== '' && function_exists('mailer_send')) {
      /**
       * $mailErr — ошибка почты
       */
      $mailErr = null;
      mailer_send($clientEmail, 'CRM2026: доступ в кабинет', "Ваш временный пароль: {$tmpPass}", [], $mailErr);
    } elseif (function_exists('sms_send')) {
      sms_send($clientPhone, 'Ваш временный пароль: ' . $tmpPass);
    }
  }

  json_ok([
    'id' => $requestId,
    'message' => 'Заявка отправлена. Мы свяжемся с вами.',
  ]);
} catch (Throwable $e) {
  audit_log('requests', 'create', 'error', [
    'error' => $e->getMessage(),
    'source' => 'landing',
  ], 'request', null, null, null);

  json_err('Ошибка отправки заявки', 500);
}
