<?php
/**
 * FILE: /site/index.php
 * ROLE: Public site entry (landing + заявка)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once ROOT_PATH . '/adm/modules/requests/settings.php';
require_once ROOT_PATH . '/adm/modules/requests/assets/php/requests_lib.php';
require_once ROOT_PATH . '/core/mailer.php';

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
 * $services — услуги (получаем через API)
 */
$services = [];

/**
 * $specMap — соответствие услуга -> специалисты (получаем через API)
 */
$specMap = [];

/**
 * Обработка внешней заявки
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['do'] ?? '') === 'request_add') {
  /**
   * $csrf — токен
   */
  $csrf = (string)($_POST['csrf'] ?? '');
  csrf_check($csrf);

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
    flash('Имя и телефон обязательны', 'warn');
    redirect(url('/'));
  }

  if ($consent !== 1) {
    audit_log('requests', 'create', 'warn', [
      'reason' => 'consent_required',
      'source' => 'landing',
      'phone' => ($clientPhone !== '' ? $clientPhone : null),
    ], 'request', null, null, null);
    flash('Нужно согласие на обработку данных', 'warn');
    redirect(url('/'));
  }

  if ($useSpecialists) {
    if ($serviceId <= 0 || $specialistId <= 0) {
      audit_log('requests', 'create', 'warn', [
        'reason' => 'service_or_specialist_required',
        'source' => 'landing',
        'service_id' => ($serviceId > 0 ? $serviceId : null),
        'specialist_id' => ($specialistId > 0 ? $specialistId : null),
      ], 'request', null, null, null);
      flash('Выберите услугу и специалиста', 'warn');
      redirect(url('/'));
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
      flash('Специалист не привязан к услуге', 'warn');
      redirect(url('/'));
    }
  }

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

    /**
     * $slotKey — ключ слота (защита от дублей)
     */
    $slotKey = null;
    if ($useSpecialists && $specialistId > 0 && $visitAt !== null) {
      $slotKey = requests_slot_key($specialistId, $visitAt);
    }

    /**
     * $durationMin — длительность (мин)
     */
    $durationMin = $serviceId > 0 ? requests_service_duration($pdo, $serviceId) : 30;

    if ($useSpecialists && $useTimeSlots) {
      if ($specialistId <= 0 || $visitDate === '' || $visitTime === '' || $visitAt === null) {
        audit_log('requests', 'create', 'warn', [
          'reason' => 'visit_required',
          'source' => 'landing',
          'specialist_id' => ($specialistId > 0 ? $specialistId : null),
        ], 'request', null, null, null);
        flash('Выберите дату и время записи', 'warn');
        redirect(url('/'));
      }

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
        flash($slotMsg, 'warn');
        redirect(url('/'));
      }
    }

    $pdo->prepare("\n      INSERT INTO " . REQUESTS_TABLE . "\n        (status, source, client_id, client_name, client_phone, client_email,
         service_id, specialist_user_id, visit_at, slot_key, duration_min, consent_at, consent_ip, consent_user_agent, consent_text_version,
         created_at, updated_at)
      VALUES
        (:status, :source, :client_id, :client_name, :client_phone, :client_email,
         :service_id, :specialist_user_id, :visit_at, :slot_key, :duration_min, NOW(), :ip, :ua, :consent_ver,
         NOW(), NOW())
    ")->execute([
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
      'action' => 'create_site_legacy',
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

    flash('Заявка отправлена. Мы свяжемся с вами.', 'ok');
  } catch (Throwable $e) {
    audit_log('requests', 'create', 'error', [
      'error' => $e->getMessage(),
      'source' => 'landing',
    ], 'request', null, null, null);

    flash('Ошибка отправки заявки', 'danger', 1);
  }

  redirect(url('/'));
}

/**
 * FLASH -> JS
 */
/**
 * $flash — список flash
 */
$flash = function_exists('flash_pull') ? (array)flash_pull() : [];
/**
 * $flashJson — JSON для JS
 */
$flashJson = json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($flashJson === false) $flashJson = '[]';

/**
 * $csrf — токен
 */
$csrf = csrf_token();

/**
 * $specMapJson — карта специалистов
 */
$specMapJson = '{}';
$servicesJson = '[]';
?>
<!doctype html>
<html lang="ru" data-theme="color">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CRM2026 — Site</title>

  <link rel="stylesheet" href="/adm/view/assets/libs/vendor/bootstrap-icons/font/bootstrap-icons.css">
  <link rel="stylesheet" href="/adm/view/assets/css/main.css">

  <style>
    html,body{ height:100%; margin:0; }
    .site-wrap{ min-height:100%; display:flex; align-items:center; justify-content:center; padding:24px; }
    .site-box{ max-width:560px; text-align:center; }
    .site-title{ margin:0 0 10px; font-size:32px; font-weight:800; }
    .site-text{ margin:0 0 18px; color:var(--text-muted); line-height:1.5; }
    .req-search{ position:relative; display:flex; flex-direction:column; gap:6px; }
    .is-hidden{ display:none !important; }
    .req-search__suggest{
      display:none;
      border:1px solid var(--border-soft);
      border-radius:10px;
      background: var(--surface-solid, #ffffff);
      color: var(--text);
      max-height:200px;
      overflow:auto;
      opacity:1;
      backdrop-filter:none;
    }
    .req-search__suggest.is-open{ display:block; }
    .req-search__suggest button{
      width:100%;
      text-align:left;
      background:transparent;
      border:0;
      padding:8px 10px;
      cursor:pointer;
      color:inherit;
    }
    .req-search__suggest button:hover{ background: var(--surface-2); }
    .req-slot-note{ font-size:12px; line-height:1.35; }
    .req-slot-note--warn{ color:#ffd27d; }
  </style>
</head>
<body>
  <div class="flashbar" id="flashbar" aria-live="polite" aria-atomic="true"></div>

  <div class="site-wrap">
    <div class="site-box">
      <div class="site-title">CRM2026</div>
      <div class="site-text">
        Публичная часть пока в разработке, но заявку можно оставить уже сейчас.
      </div>
      <button class="btn btn--accent" type="button" id="btnOpenRequest">Оставить заявку</button>
    </div>
  </div>

  <template id="tplRequestForm">
    <form method="post" action="<?= h(url('/core/internal_api.php?m=requests&do=api_request_add')) ?>" data-requests-api="1" data-requests-api-url="<?= h(url('/core/internal_api.php?m=requests&do=api_request_add')) ?>">
      <input type="hidden" name="do" value="request_add">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
        <div class="card__body" style="display:flex; flex-direction:column; gap:10px;">
          <label class="field field--stack">
            <span class="field__label">Имя</span>
            <input class="select" style="height:40px;" name="client_name" required>
          </label>

          <label class="field field--stack">
            <span class="field__label">Телефон</span>
            <input class="select" style="height:40px;" name="client_phone" required>
          </label>

          <label class="field field--stack">
            <span class="field__label">Email (необязательно, нужен для пароля)</span>
            <input class="select" style="height:40px;" name="client_email" type="email">
          </label>

          <?php if ($useSpecialists): ?>
            <div class="field field--stack req-search" data-requests-service-wrap="1">
              <span class="field__label">Услуга</span>
              <input class="select" style="height:40px;" type="text" id="reqServiceSearch" placeholder="Поиск услуги" autocomplete="off">
              <div class="req-search__suggest scroll-thin" id="reqServiceSuggest"></div>
              <input type="hidden" name="service_id" id="reqServiceId">
            </div>

            <div class="field field--stack req-search is-hidden" data-requests-specialist-wrap="1">
              <span class="field__label">Специалист</span>
              <input class="select" style="height:40px;" type="text" id="reqSpecialistSearch" placeholder="Поиск специалиста" autocomplete="off">
              <div class="req-search__suggest scroll-thin" id="reqSpecialistSuggest"></div>
              <input type="hidden" name="specialist_user_id" id="reqSpecialistId">
            </div>

            <?php if ($useTimeSlots): ?>
              <div class="field field--stack is-hidden" data-requests-slot-wrap="1" data-requests-use-slots="1" data-requests-slots-api="<?= h(url('/core/internal_api.php?m=calendar&do=api_slots_day')) ?>">
                <span class="field__label">Дата</span>
                <input class="select" style="height:40px;" name="visit_date" type="date" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" data-requests-slot-date="1">
              </div>
              <div class="field field--stack is-hidden" data-requests-slot-wrap="1" data-requests-slot-time-wrap="1">
                <span class="field__label">Время</span>
                <select class="select" name="visit_time" data-requests-slot-select="1">
                  <option value="">Время</option>
                </select>
              </div>
              <div class="field field--stack is-hidden" data-requests-slot-wrap="1" data-requests-slot-note-wrap="1">
                <div class="muted req-slot-note is-hidden" data-requests-slot-note="1"></div>
              </div>
            <?php endif; ?>
          <?php endif; ?>

          <label class="field" style="display:flex; gap:8px; align-items:center;">
            <input type="checkbox" name="consent" value="1" required>
            <span class="muted" style="font-size:12px;">Согласен на обработку персональных данных</span>
          </label>

          <button class="btn btn--accent" type="submit">Отправить</button>
        </div>
      </div>
    </form>
  </template>

  <div class="modal" id="modal" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal__backdrop" data-modal-close="1"></div>
    <div class="modal__panel" role="document" aria-label="Modal">
      <div class="modal__head">
        <div class="modal__title" id="modalTitle">Заявка</div>
        <button class="iconbtn" type="button" data-modal-close="1" aria-label="Close modal">×</button>
      </div>
      <div class="modal__body" id="modalBody"></div>
      <div class="modal__foot">
        <button class="btn" type="button" data-modal-close="1">Закрыть</button>
      </div>
    </div>
  </div>

  <script>
    window.__FLASH__ = <?= $flashJson ?>;
  </script>
  <script src="/adm/view/assets/js/main.js"></script>

  <script>
    (function(){
      var btn = document.getElementById('btnOpenRequest');
      var tpl = document.getElementById('tplRequestForm');
      var services = <?= $servicesJson ?>;
      var specMap = <?= $specMapJson ?>;
      var useSlots = <?= $useTimeSlots ? 'true' : 'false' ?>;
      var slotsApi = <?= json_encode(url('/core/internal_api.php?m=calendar&do=api_slots_day'), JSON_UNESCAPED_SLASHES) ?>;
      var catalogApi = <?= json_encode(url('/core/internal_api.php?m=requests&do=api_services'), JSON_UNESCAPED_SLASHES) ?>;

      function flashShow(text, bg) {
        var bar = document.getElementById('flashbar');
        if (!bar) {
          alert(text || '');
          return;
        }
        var el = document.createElement('div');
        el.className = 'flash';
        el.style.background = (bg === 'ok') ? 'linear-gradient(90deg,#47d18c,#2b9efb)' :
          (bg === 'danger') ? 'linear-gradient(90deg,#ff7a59,#ff3b3b)' :
          'linear-gradient(90deg,#6c7c93,#9aa6b2)';
        var t = document.createElement('div');
        t.className = 'flash__text';
        t.textContent = String(text || '');
        var x = document.createElement('button');
        x.className = 'flash__close';
        x.type = 'button';
        x.textContent = '×';
        x.addEventListener('click', function () { el.remove(); });
        el.appendChild(t);
        el.appendChild(x);
        bar.appendChild(el);
        setTimeout(function(){ if (el.isConnected) el.remove(); }, 6000);
      }

      function loadCatalog() {
        return fetch(catalogApi, { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (payload) {
            if (!payload || payload.ok !== true || !payload.data) return false;
            var data = payload.data || {};
            services = Array.isArray(data.services) ? data.services : [];
            specMap = data.spec_map || {};
            return true;
          })
          .catch(function () { return false; });
      }

      function buildSuggest(items, query, suggest, allowEmpty) {
        if (!suggest) return;
        var q = (query || '').toLowerCase().trim();
        suggest.innerHTML = '';

        var list = items || [];
        if (q) {
          list = list.filter(function (it) {
            return String(it.name || '').toLowerCase().indexOf(q) !== -1;
          });
        } else if (!allowEmpty) {
          list = [];
        }

        list = list.slice(0, 8);
        if (!list.length) {
          suggest.classList.remove('is-open');
          return;
        }

        list.forEach(function (it) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.textContent = String(it.name || '');
          btn.setAttribute('data-value', String(it.id || ''));
          suggest.appendChild(btn);
        });

        suggest.classList.add('is-open');
      }

      function slotNoteSet(text, warn) {
        var note = document.querySelector('[data-requests-slot-note="1"]');
        if (!note) return;

        var value = String(text || '').trim();
        if (!value) {
          note.textContent = '';
          note.classList.add('is-hidden');
          note.classList.remove('req-slot-note--warn');
          return;
        }

        note.textContent = value;
        note.classList.remove('is-hidden');
        note.classList.toggle('req-slot-note--warn', !!warn);
      }

      function slotTimeVisible(visible) {
        var wrap = document.querySelector('[data-requests-slot-time-wrap="1"]');
        if (!wrap) return;
        wrap.classList.toggle('is-hidden', !visible);
      }

      function slotSubmitBlocked(blocked) {
        var form = document.querySelector('#modalBody form[data-requests-api="1"]');
        if (!form) return;
        var submit = form.querySelector('button[type="submit"], input[type="submit"]');
        if (!submit) return;

        if (blocked) {
          if (!submit.hasAttribute('data-slot-prev-disabled')) {
            submit.setAttribute('data-slot-prev-disabled', submit.disabled ? '1' : '0');
          }
          submit.disabled = true;
          return;
        }

        var prev = submit.getAttribute('data-slot-prev-disabled');
        if (prev !== null) {
          submit.disabled = (prev === '1');
          submit.removeAttribute('data-slot-prev-disabled');
        }
      }

      function loadSlots() {
        if (!useSlots) return;
        var dateInput = document.querySelector('[data-requests-slot-date="1"]');
        var timeSelect = document.querySelector('[data-requests-slot-select="1"]');
        var specId = (document.getElementById('reqSpecialistId') || {}).value || '';
        var serviceId = (document.getElementById('reqServiceId') || {}).value || '';
        if (!dateInput || !timeSelect) return;

        var dateVal = (dateInput.value || '').trim();
        timeSelect.innerHTML = '<option value="">Время</option>';
        slotNoteSet('', false);
        slotSubmitBlocked(false);

        if (!specId) {
          timeSelect.disabled = true;
          slotTimeVisible(false);
          return;
        }

        if (!dateVal) {
          timeSelect.disabled = true;
          slotTimeVisible(true);
          return;
        }

        slotTimeVisible(true);
        timeSelect.disabled = true;
        timeSelect.innerHTML = '<option value="">Загрузка...</option>';

        var durationVal = 30;
        if (serviceId) {
          var srv = services.find(function (it) { return String(it.id) === String(serviceId); });
          if (srv) {
            var durNum = parseInt(srv.duration_min || '0', 10);
            if (durNum > 0) durationVal = durNum;
          }
        }

        var url = slotsApi
          + '&specialist_id=' + encodeURIComponent(specId)
          + '&date=' + encodeURIComponent(dateVal)
          + '&duration_min=' + encodeURIComponent(String(durationVal));
        fetch(url, { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (payload) {
            if (!payload || payload.ok !== true || !payload.data) {
              slotNoteSet('Не удалось загрузить слоты', true);
              slotSubmitBlocked(true);
              return;
            }

            var data = payload.data || {};
            var slots = Array.isArray(data.slots) ? data.slots : [];
            var reason = String(data.reason || '');
            var message = String(data.message || '').trim();
            var hasSchedule = (data.has_schedule !== false);
            timeSelect.innerHTML = '<option value="">Время</option>';

            if (!slots.length) {
              var empty = document.createElement('option');
              empty.value = '';
              if (reason === 'day_off') {
                empty.textContent = 'Выходной';
              } else if (reason === 'past_date') {
                empty.textContent = 'Прошедшая дата';
              } else if (!hasSchedule || reason === 'no_schedule') {
                empty.textContent = 'Нет расписания';
              } else {
                empty.textContent = 'Нет слотов';
              }
              timeSelect.appendChild(empty);
              timeSelect.disabled = true;

              if (reason === 'day_off') {
                slotTimeVisible(false);
                slotNoteSet(message || 'У специалиста выходной в выбранную дату', true);
              } else if (reason === 'past_date') {
                slotTimeVisible(false);
                slotNoteSet(message || 'Запись на прошедшую дату недоступна', true);
              } else if (!hasSchedule || reason === 'no_schedule') {
                slotTimeVisible(false);
                slotNoteSet(message || 'Нет рабочего расписания на выбранную дату', true);
              } else {
                slotTimeVisible(true);
                slotNoteSet(message || 'Нет свободных слотов на выбранную дату', false);
              }
              slotSubmitBlocked(true);
            } else {
              slots.forEach(function (slot) {
                var opt = document.createElement('option');
                opt.value = String(slot);
                opt.textContent = String(slot);
                timeSelect.appendChild(opt);
              });
              timeSelect.disabled = false;
              slotTimeVisible(true);
              slotNoteSet('', false);
              slotSubmitBlocked(false);
            }
          })
          .catch(function () {
            timeSelect.disabled = true;
            timeSelect.innerHTML = '<option value="">Время</option>';
            slotTimeVisible(true);
            slotNoteSet('Не удалось загрузить слоты, попробуйте еще раз', true);
            slotSubmitBlocked(true);
          });
      }

      if (btn && tpl && window.App && window.App.modal) {
        btn.addEventListener('click', function(){
          window.App.modal.open('Оставить заявку', tpl.innerHTML);
          setTimeout(function(){
            var serviceInput = document.getElementById('reqServiceSearch');
            var serviceSuggest = document.getElementById('reqServiceSuggest');
            var serviceIdInput = document.getElementById('reqServiceId');
            var specInput = document.getElementById('reqSpecialistSearch');
            var specSuggest = document.getElementById('reqSpecialistSuggest');
            var specIdInput = document.getElementById('reqSpecialistId');
            var form = document.querySelector('#modalBody form[data-requests-api="1"]');

            function updateSteps() {
              var hasService = serviceIdInput && serviceIdInput.value;
              var hasSpec = specIdInput && specIdInput.value;
              var specWrap = document.querySelector('[data-requests-specialist-wrap="1"]');
              if (specWrap) {
                specWrap.classList.toggle('is-hidden', !hasService);
              }
              document.querySelectorAll('[data-requests-slot-wrap="1"]').forEach(function (el) {
                el.classList.toggle('is-hidden', !hasSpec);
              });
              if (!hasSpec) {
                slotTimeVisible(false);
                slotNoteSet('', false);
                slotSubmitBlocked(false);
              }
            }

            function setService(id, name) {
              if (serviceIdInput) serviceIdInput.value = String(id || '');
              if (serviceInput) serviceInput.value = String(name || '');
              if (specInput) specInput.value = '';
              if (specIdInput) specIdInput.value = '';
              if (specSuggest) {
                specSuggest.innerHTML = '';
                specSuggest.classList.remove('is-open');
              }
              updateSteps();
              loadSlots();
            }

            function setSpecialist(id, name) {
              if (specIdInput) specIdInput.value = String(id || '');
              if (specInput) specInput.value = String(name || '');
              if (specSuggest) specSuggest.classList.remove('is-open');
              updateSteps();
              loadSlots();
            }

            if (serviceInput && serviceSuggest) {
              serviceInput.addEventListener('input', function () {
                buildSuggest(services, serviceInput.value || '', serviceSuggest, false);
              });
              serviceInput.addEventListener('blur', function () {
                if ((serviceIdInput && serviceIdInput.value) || !serviceInput.value) return;
                var val = serviceInput.value.trim().toLowerCase();
                if (!val) return;
                var picked = services.find(function (it) {
                  return String(it.name || '').toLowerCase() === val;
                });
                if (picked) {
                  setService(picked.id, picked.name);
                }
              });
              serviceSuggest.addEventListener('click', function (e) {
                var t = e.target;
                if (!(t instanceof HTMLElement)) return;
                var val = t.getAttribute('data-value');
                if (!val) return;
                var picked = services.find(function (it) { return String(it.id) === String(val); });
                if (picked) {
                  setService(picked.id, picked.name);
                  serviceSuggest.classList.remove('is-open');
                }
              });
            }

            if (specInput && specSuggest) {
              function currentSpecs() {
                var sid = serviceIdInput ? (serviceIdInput.value || '') : '';
                if (!sid || !specMap[sid]) return [];
                return specMap[sid] || [];
              }

              specInput.addEventListener('input', function () {
                buildSuggest(currentSpecs(), specInput.value || '', specSuggest, false);
              });
              specInput.addEventListener('focus', function () {
                buildSuggest(currentSpecs(), specInput.value || '', specSuggest, true);
              });
              specInput.addEventListener('blur', function () {
                if ((specIdInput && specIdInput.value) || !specInput.value) return;
                var val = specInput.value.trim().toLowerCase();
                if (!val) return;
                var list = currentSpecs();
                var picked = list.find(function (it) {
                  return String(it.name || '').toLowerCase() === val;
                });
                if (picked) {
                  setSpecialist(picked.id, picked.name);
                }
              });
              specSuggest.addEventListener('click', function (e) {
                var t = e.target;
                if (!(t instanceof HTMLElement)) return;
                var val = t.getAttribute('data-value');
                if (!val) return;
                var list = currentSpecs();
                var picked = list.find(function (it) { return String(it.id) === String(val); });
                if (picked) {
                  setSpecialist(picked.id, picked.name);
                }
              });
            }

            updateSteps();

            loadCatalog().then(function (ok) {
              if (!ok) {
                flashShow('Не удалось загрузить каталог услуг', 'danger');
                return;
              }
            });

            if (useSlots) {
              var dateInput = document.querySelector('[data-requests-slot-date="1"]');
              if (dateInput) {
                dateInput.addEventListener('change', function () {
                  loadSlots();
                });
              }
              loadSlots();
            }

            if (form) {
              form.addEventListener('submit', function (e) {
                e.preventDefault();
                var url = form.getAttribute('data-requests-api-url') || form.action || '';
                if (!url) return;
                var fd = new FormData(form);
                fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
                  .then(function (r) { return r.json(); })
                  .then(function (payload) {
                    if (payload && payload.ok === true && payload.data) {
                      flashShow(payload.data.message || 'Заявка отправлена', 'ok');
                      if (window.App && window.App.modal && typeof window.App.modal.close === 'function') {
                        window.App.modal.close();
                      }
                    } else {
                      var msg = (payload && payload.error) ? payload.error : 'Ошибка отправки';
                      flashShow(msg, 'danger');
                    }
                  })
                  .catch(function () {
                    flashShow('Ошибка сети', 'danger');
                  });
              });
            }

            if (!document.body.dataset.reqSearchBound) {
              document.body.dataset.reqSearchBound = '1';
              document.addEventListener('click', function (e) {
                var t = e.target;
                if (!(t instanceof HTMLElement)) return;
                if (!t.closest('.req-search')) {
                  document.querySelectorAll('.req-search__suggest.is-open').forEach(function (el) {
                    el.classList.remove('is-open');
                  });
                }
              });
            }
          }, 0);
        });
      }
    })();
  </script>
</body>
</html>
