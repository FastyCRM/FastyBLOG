<?php
/**
 * FILE: /adm/modules/personal_file/personal_file.php
 * ROLE: VIEW — личное дело клиента (UI only)
 * CONNECTIONS:
 *  - db()
 *  - csrf_token()
 *  - module_allowed_roles(), acl_guard()
 *  - url(), h()
 *
 * NOTES:
 *  - Только UI. Вся логика изменения данных через handler do=...
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/personal_file_lib.php';

/**
 * ACL: доступ к модулю personal_file
 */
acl_guard(module_allowed_roles('personal_file'));

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $csrf — CSRF токен
 */
$csrf = csrf_token();

/**
 * $uid — пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $roles — роли пользователя
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

/**
 * $canManage — админ/менеджер
 */
$canManage = personal_file_can_manage($roles);

/**
 * $searchInitial — строка поиска клиента
 */
$searchInitial = trim((string)($_GET['q'] ?? ''));

/**
 * $clientId — ID клиента
 */
$clientId = (int)($_GET['client_id'] ?? $_GET['id'] ?? 0);

/**
 * $client — данные клиента
 */
$client = ($clientId > 0) ? personal_file_get_client($pdo, $clientId) : null;

/**
 * $accessDenied — нет доступа к выбранному клиенту
 */
$accessDenied = false;
if ($clientId > 0 && !personal_file_user_can_access($pdo, $clientId, $uid, $roles)) {
  $accessDenied = true;
  $clientId = 0;
  $client = null;
}

/**
 * $clientsSearchApi — показывать выдачу поиска
 */
$clientsSearchApi = url('/adm/index.php?m=clients&do=api_search');

/**
 * $canAccessTab — доступ к вкладке "Доступы"
 */
$canAccessTab = personal_file_user_can_access($pdo, $clientId, $uid, $roles);

/**
 * $notes — заметки
 */
$notes = $client ? personal_file_get_notes($pdo, $clientId, 200) : [];

/**
 * $noteFiles — map note_id => files
 */
$noteIds = array_map(static function ($n) { return (int)($n['id'] ?? 0); }, $notes);
$noteFiles = $noteIds ? personal_file_get_note_files_map($pdo, $noteIds) : [];

/**
 * $services — услуги
 */
$services = $client ? personal_file_get_services($pdo, $clientId) : [];
/**
 * $serviceGroups — услуги, сгруппированные по заявкам
 */
$serviceGroups = [];
$serviceGroupOrder = [];
if ($services) {
  $i = 0;
  foreach ($services as $s) {
    $rid = (int)($s['request_id'] ?? 0);
    $key = $rid > 0 ? ('r' . $rid) : ('row' . $i);
    if (!isset($serviceGroups[$key])) {
      $date = (string)($s['invoice_date'] ?? $s['created_at'] ?? '');
      $serviceGroups[$key] = [
        'request_id' => $rid,
        'date' => $date,
        'total' => 0,
        'items' => [],
      ];
      $serviceGroupOrder[] = $key;
    }
    $serviceGroups[$key]['items'][] = $s;
    $serviceGroups[$key]['total'] += (int)($s['total'] ?? 0);
    $i++;
  }
}

/**
 * $accessTypes — типы доступов
 */
$accessTypes = $client ? personal_file_get_access_types($pdo) : [];

/**
 * $accessTtls — сроки жизни
 */
$accessTtls = $client ? personal_file_get_access_ttls($pdo) : [];

/**
 * $accesses — список доступов
 */
$accesses = ($client && $canAccessTab) ? personal_file_get_accesses($pdo, $clientId) : [];

/**
 * $activeTab — активная вкладка
 */
$activeTab = (string)($_GET['tab'] ?? 'general');
if (!in_array($activeTab, ['general','services','access'], true)) {
  $activeTab = 'general';
}

/**
 * Подготовка данных клиента
 */
$firstName = (string)($client['first_name'] ?? '');
$lastName = (string)($client['last_name'] ?? '');
$middleName = (string)($client['middle_name'] ?? '');
$phone = (string)($client['phone'] ?? '');
$email = (string)($client['email'] ?? '');
$inn = (string)($client['inn'] ?? '');
$birthDate = (string)($client['birth_date'] ?? '');
$photoPath = (string)($client['photo_path'] ?? '');
$status = (string)($client['status'] ?? 'active');

$fio = trim($lastName . ' ' . $firstName . ' ' . $middleName);
$photoUrl = ($photoPath !== '') ? h(url('/adm/index.php?m=personal_file&do=file_get&type=photo&client_id=' . $clientId)) : '';
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/personal_file/assets/css/main.css')) ?>">
<link rel="stylesheet" href="<?= h(url('/adm/view/assets/css/lightbox/lightbox.css')) ?>">

<div class="card pf-search-card">
  <div class="card__body">
    <div class="pf-search-top">
      <div class="pf-search-form"
           data-pf-client-search="1"
           data-pf-search-api="<?= h($clientsSearchApi) ?>"
           data-pf-search-active-id="<?= (int)$clientId ?>">
        <input class="select"
               type="text"
               value="<?= h($searchInitial) ?>"
               placeholder="Поиск клиента: ИНН / телефон / имя / фамилия"
               data-pf-search-input="1"
               autocomplete="off">
      </div>
      <?php if ($canManage): ?>
        <div class="pf-search-actions">
          <button class="iconbtn iconbtn--sm"
                  type="button"
                  data-pf-open-modal="1"
                  data-pf-modal="<?= h(url('/adm/index.php?m=personal_file&do=modal_settings')) ?>"
                  aria-label="Настройки"
                  title="Настройки">
            <i class="bi bi-gear"></i>
          </button>
        </div>
      <?php endif; ?>
    </div>

    <div class="pf-search-results scroll-thin is-hidden" data-pf-search-results="1"></div>
    <div class="muted is-hidden" data-pf-search-empty="1">Ничего не найдено по вашему запросу.</div>
    <div class="muted" data-pf-search-hint="1">Начните поиск клиента по имени, телефону или ИНН.</div>
    <?php if ($accessDenied): ?>
      <div class="muted pf-search-warning">Нет доступа к выбранному клиенту. Доступны только назначенные клиенты в статусах confirmed/in_work.</div>
    <?php endif; ?>
  </div>
</div>

<?php if (!$client): ?>
  <script src="<?= h(url('/adm/modules/personal_file/assets/js/main.js')) ?>"></script>
  <?php return; ?>
<?php endif; ?>
<div class="pf-tabs" data-pf-tabs="1">
  <button class="pf-tab <?= $activeTab === 'general' ? 'is-active' : '' ?>" type="button" data-pf-tab="general">Общая</button>
  <button class="pf-tab <?= $activeTab === 'services' ? 'is-active' : '' ?>" type="button" data-pf-tab="services">Услуги</button>
  <button class="pf-tab <?= $activeTab === 'access' ? 'is-active' : '' ?>" type="button" data-pf-tab="access">Доступы</button>
</div>

<!-- TAB: GENERAL -->
<div class="pf-panel <?= $activeTab === 'general' ? 'is-active' : '' ?>" data-pf-panel="general">
  <div class="pf-general-grid">
    <div class="pf-general-left">
      <div class="card pf-client-card">
        <div class="card__body">
          <div class="pf-client-head">
            <div class="pf-client-photo">
              <?php if ($photoUrl !== ''): ?>
                <img src="<?= $photoUrl ?>" alt="Фото клиента">
              <?php else: ?>
                <div class="pf-photo__placeholder">Фото</div>
              <?php endif; ?>
            </div>
            <div class="pf-client-info">
              <div class="pf-client-name"><?= h($fio !== '' ? $fio : '—') ?></div>
              <div class="pf-client-line">Телефон: <span class="mono"><?= h($phone !== '' ? $phone : '—') ?></span></div>
              <div class="pf-client-line">Email: <span><?= h($email !== '' ? $email : '—') ?></span></div>
              <div class="pf-client-line">Дата рождения: <span class="mono"><?= h($birthDate !== '' ? $birthDate : '—') ?></span></div>
              <div class="pf-client-line">ИНН: <span class="mono"><?= h($inn !== '' ? $inn : '—') ?></span></div>
              <div class="pf-client-line muted">Статус: <?= h($status) ?></div>
            </div>
          </div>

          <div class="pf-client-actions">
            <button class="btn btn--ghost"
                    type="button"
                    data-pf-open-modal="1"
                    data-pf-modal="<?= h(url('/adm/index.php?m=clients&do=modal_update&id=' . $clientId . '&return_url=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? '')))) ?>">
              Изменить
            </button>
          </div>
        </div>
      </div>

      <div class="card pf-note-add-card" style="margin-top:12px;">
        <div class="card__head">
          <div class="card__title">Добавить заметку</div>
        </div>
        <div class="card__body">
          <form method="post" enctype="multipart/form-data" action="<?= h(url('/adm/index.php?m=personal_file&do=note_add')) ?>" class="pf-note-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">

            <label class="field field--stack">
              <span class="field__label">Заметка</span>
              <textarea class="select" name="note_text" rows="3" placeholder="Текст заметки"></textarea>
            </label>

            <label class="field field--stack pf-note-file">
              <span class="field__label">Файлы</span>
              <input class="select" type="file" name="note_files[]" multiple>
            </label>

            <div class="pf-note-actions">
              <button class="btn btn--accent" type="submit">Добавить</button>
            </div>
          </form>

          <form method="post" action="<?= h(url('/adm/index.php?m=personal_file&do=notes_pdf')) ?>" class="pf-pdf">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
            <label class="field field--stack">
              <span class="field__label">Период с</span>
              <input class="select" type="date" name="date_from">
            </label>
            <label class="field field--stack">
              <span class="field__label">по</span>
              <input class="select" type="date" name="date_to">
            </label>
            <button class="btn btn--ghost" type="submit">PDF заметок</button>
          </form>
        </div>
      </div>
    </div>

    <div class="pf-general-right">
      <div class="card pf-notes-card">
        <div class="card__head">
          <div class="card__title">Заметки</div>
        </div>
        <div class="card__body">
          <div class="pf-notes scroll-thin">
            <?php foreach ($notes as $n): ?>
              <?php
                $nid = (int)($n['id'] ?? 0);
                $nText = (string)($n['note_text'] ?? '');
                $nUser = (string)($n['user_name'] ?? '—');
                $nDate = (string)($n['created_at'] ?? '');
                $files = $noteFiles[$nid] ?? [];
              ?>
              <div class="pf-note">
                <div class="pf-note__head">
                  <div class="pf-note__author"><?= h($nUser) ?></div>
                  <div class="pf-note__date muted"><?= h($nDate) ?></div>
                </div>
                <div class="pf-note__text"><?= h($nText) ?></div>

                <?php if ($files): ?>
                  <div class="pf-note__files">
                    <?php foreach ($files as $f): ?>
                      <?php
                        $fid = (int)($f['id'] ?? 0);
                        $orig = (string)($f['orig_name'] ?? '');
                        $isImg = ((int)($f['is_image'] ?? 0) === 1);
                        $fileUrl = h(url('/adm/index.php?m=personal_file&do=file_get&id=' . $fid));
                      ?>
                      <?php if ($isImg): ?>
                        <a class="pf-file pf-file--img"
                           href="<?= $fileUrl ?>"
                           data-lightbox="1"
                           data-lightbox-group="note-<?= $nid ?>"
                           data-lightbox-src="<?= $fileUrl ?>">
                          <img src="<?= $fileUrl ?>" alt="">
                        </a>
                      <?php else: ?>
                        <a class="pf-file" href="<?= $fileUrl ?>" target="_blank">
                          <i class="bi bi-file-earmark-text"></i>
                          <span><?= h($orig !== '' ? $orig : 'Файл') ?></span>
                        </a>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <?php if (!$notes): ?>
              <div class="muted">Заметок нет.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- TAB: SERVICES -->
<div class="pf-panel <?= $activeTab === 'services' ? 'is-active' : '' ?>" data-pf-panel="services">
  <div class="card">
    <div class="card__head">
      <div class="card__title">Услуги клиента</div>
      <div class="card__hint muted">История выполненных услуг</div>
    </div>
    <div class="card__body">
      <?php if ($serviceGroupOrder): ?>
        <div class="pf-services">
          <?php foreach ($serviceGroupOrder as $key): ?>
            <?php
              $g = $serviceGroups[$key];
              $gDate = (string)($g['date'] ?? '');
              $gTotal = (int)($g['total'] ?? 0);
              $gRequestId = (int)($g['request_id'] ?? 0);
            ?>
            <div class="pf-service-group">
              <div class="pf-service-head">
                <div class="pf-service-date mono"><?= h($gDate !== '' ? $gDate : '—') ?></div>
                <div class="pf-service-title"><?= $gRequestId > 0 ? ('Заявка #' . $gRequestId) : 'Заявка' ?></div>
                <div class="pf-service-links">
                  <a class="muted" href="#" onclick="return false">Счёт</a>
                  <span class="muted">/</span>
                  <a class="muted" href="#" onclick="return false">Акт</a>
                </div>
                <div class="pf-service-total">Итого: <strong><?= $gTotal > 0 ? $gTotal : '—' ?></strong></div>
              </div>

              <div class="tablewrap">
                <table class="table pf-service-table">
                  <thead>
                    <tr>
                      <th>Услуга</th>
                      <th style="width:80px">Кол-во</th>
                      <th style="width:120px">Сумма</th>
                      <th>Комментарий</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($g['items'] as $s): ?>
                      <?php
                        $name = (string)($s['service_name'] ?? '');
                        $qty = (int)($s['qty'] ?? 0);
                        $total = (int)($s['total'] ?? 0);
                        $comment = '';
                      ?>
                      <tr>
                        <td><?= h($name !== '' ? $name : '—') ?></td>
                        <td><?= $qty > 0 ? $qty : '—' ?></td>
                        <td><?= $total > 0 ? $total : '—' ?></td>
                        <td><?= h($comment !== '' ? $comment : '—') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="muted" style="padding:14px;">Услуг нет.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- TAB: ACCESS -->
<div class="pf-panel <?= $activeTab === 'access' ? 'is-active' : '' ?>" data-pf-panel="access">
  <?php if (!$canAccessTab): ?>
    <div class="card">
      <div class="card__body muted">Нет доступа к вкладке «Доступы».</div>
    </div>
  <?php else: ?>
    <div class="pf-access-grid">
      <div class="pf-access-left">
    <?php if ($canManage): ?>
      <div class="card">
        <div class="card__head">
          <div class="card__title">Добавить доступ</div>
          <div class="card__hint muted">Логины и пароли шифруются</div>
        </div>
        <div class="card__body">
          <form method="post" action="<?= h(url('/adm/index.php?m=personal_file&do=access_add')) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
            <input type="hidden" name="return_url" value="<?= h(url('/adm/index.php?m=personal_file&client_id=' . $clientId . '&tab=access')) ?>">

            <div class="pf-form-grid">
              <label class="field field--stack">
                <span class="field__label">Тип доступа</span>
                <select class="select" name="type_id" data-ui-select="1">
                  <option value="">Выберите тип</option>
                  <?php foreach ($accessTypes as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= h((string)($t['name'] ?? '')) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="field field--stack">
                <span class="field__label">Срок жизни</span>
                <select class="select" name="ttl_id" data-ui-select="1">
                  <option value="">Выберите срок</option>
                  <?php foreach ($accessTtls as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= h((string)($t['name'] ?? '')) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="field field--stack">
                <span class="field__label">Логин</span>
                <input class="select" name="login" placeholder="Логин">
              </label>

              <label class="field field--stack">
                <span class="field__label">Пароль</span>
                <input class="select" name="password" placeholder="Пароль">
              </label>

              <label class="field field--stack">
                <span class="field__label">Подтверждение пароля</span>
                <input class="select" name="password_confirm" placeholder="Повторите пароль">
              </label>

              <label class="field field--stack">
                <span class="field__label">Напоминание (заглушка)</span>
                <input class="select" type="date" name="remind_at">
              </label>
            </div>

            <div style="margin-top:12px;">
              <button class="btn btn--accent" type="submit">Добавить</button>
            </div>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card__body muted">Только просмотр доступов (без редактирования).</div>
      </div>
    <?php endif; ?>
      </div>

      <div class="pf-access-right">
    <div class="card">
      <div class="card__head">
        <div class="card__title">Список доступов</div>
      </div>
      <div class="card__body">
        <div class="tablewrap">
          <table class="table pf-access-table"><thead><tr><th class="pf-col-type">Тип</th>
                <th class="pf-col-login">Логин</th>
                <th class="pf-col-pass">Пароль</th>
                <th class="pf-col-ttl">Срок</th>
                <th class="pf-col-until">До</th>
                <th class="t-right pf-col-actions"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($accesses as $a): ?>
                <?php
                  $aid = (int)($a['id'] ?? 0);
                  $typeName = (string)($a['type_name'] ?? '—');
                  $ttlName = (string)($a['ttl_name'] ?? '—');
                  $expires = (string)($a['expires_at'] ?? '');
                ?>
                <tr>
                  <td><?= h($typeName) ?></td>
                  <td>
                    <span class="pf-secret" data-pf-secret="login" data-id="<?= (int)$aid ?>">••••••</span>
                  </td>
                  <td>
                    <span class="pf-secret" data-pf-secret="password" data-id="<?= (int)$aid ?>">••••••</span>
                  </td>
                  <td><?= h($ttlName) ?></td>
                  <td class="mono"><?= h($expires !== '' ? $expires : '—') ?></td>
                  <td class="t-right">
                    <div class="table__actions">
                      <button class="iconbtn iconbtn--sm"
                              type="button"
                              data-pf-reveal="1"
                              data-id="<?= (int)$aid ?>"
                              data-field="login"
                              aria-label="Показать логин"
                              title="Показать логин">
                        <i class="bi bi-eye"></i>
                      </button>
                      <button class="iconbtn iconbtn--sm"
                              type="button"
                              data-pf-reveal="1"
                              data-id="<?= (int)$aid ?>"
                              data-field="password"
                              aria-label="Показать пароль"
                              title="Показать пароль">
                        <i class="bi bi-eye-slash"></i>
                      </button>
                      <button class="iconbtn iconbtn--sm"
                              type="button"
                              data-pf-copy="1"
                              data-id="<?= (int)$aid ?>"
                              data-field="login"
                              aria-label="Копировать логин"
                              title="Копировать логин">
                        <i class="bi bi-clipboard"></i>
                      </button>
                      <button class="iconbtn iconbtn--sm"
                              type="button"
                              data-pf-copy="1"
                              data-id="<?= (int)$aid ?>"
                              data-field="password"
                              aria-label="Копировать пароль"
                              title="Копировать пароль">
                        <i class="bi bi-clipboard-check"></i>
                      </button>

                      <?php if ($canManage): ?>
                        <form method="post"
                              action="<?= h(url('/adm/index.php?m=personal_file&do=access_delete')) ?>"
                              class="table__actionform"
                              onsubmit="return confirm('Удалить доступ?');">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$aid ?>">
                          <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
                          <input type="hidden" name="return_url" value="<?= h(url('/adm/index.php?m=personal_file&client_id=' . $clientId . '&tab=access')) ?>">
                          <button class="iconbtn iconbtn--sm"
                                  type="submit"
                                  aria-label="Удалить"
                                  title="Удалить">
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$accesses): ?>
                <tr>
                  <td colspan="6" class="muted" style="padding:14px;">Доступов нет.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="<?= h(url('/core/lightbox/lightbox.js')) ?>"></script>
<script src="<?= h(url('/adm/modules/personal_file/assets/js/main.js')) ?>"></script>
