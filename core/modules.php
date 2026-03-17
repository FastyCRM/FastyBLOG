<?php
/**
 * FILE: /core/modules.php
 * ROLE: Работа с модулями (меню + ACL) ТОЛЬКО через БД
 * CONNECTIONS:
 *  - db() из /core/db.php
 *
 * КАНОН:
 *  - settings.php модулей НЕ источник прав и НЕ источник меню
 *  - источник истины: таблица modules в БД
 *
 * ТАБЛИЦА modules (актуально):
 *  - id, code, name, icon, sort, enabled, menu, roles, has_settings
 */

declare(strict_types=1);

/**
 * Нормализует значение icon под bootstrap-icons.
 *
 * Храним/рендерим как CSS-класс:
 *  - "bi bi-gear"
 *  - "bi bi-boxes"
 *
 * Допускаем вход:
 *  - "gear"        -> "bi bi-gear"
 *  - "bi-gear"     -> "bi bi-gear"
 *  - "bi bi-gear"  -> "bi bi-gear"
 *
 * @param string $icon Исходное значение из БД/формы
 * @return string Нормализованный CSS-класс
 */
function modules_norm_icon_class(string $icon): string
{
    // $icon — строка иконки, может быть пустой/в разном формате
    $icon = trim($icon);

    // Если пусто — дефолтная иконка
    if ($icon === '') {
        return 'bi bi-dot';
    }

    // Если уже полный класс "bi bi-xxx" — оставляем как есть (чуть нормализуем пробелы)
    if (strpos($icon, 'bi bi-') === 0) {
        $icon = preg_replace('/\s+/', ' ', $icon);
        return $icon ?: 'bi bi-dot';
    }

    // Если пришло "bi-xxx" — превратим в "bi bi-xxx"
    if (strpos($icon, 'bi-') === 0) {
        return 'bi ' . $icon;
    }

    // Если пришло "xxx" — превратим в "bi bi-xxx"
    $icon = ltrim($icon, '-');
    return 'bi bi-' . $icon;
}

/**
 * Парсит JSON ролей из БД.
 *
 * Возвращает [] если NULL/пусто/битый json.
 * Пример JSON в БД: ["admin","manager"]
 *
 * @param mixed $rolesJson Значение поля roles из БД (json или null)
 * @return array<int,string> Массив ролей
 */
function modules_parse_roles($rolesJson): array
{
    // roles может быть NULL
    if ($rolesJson === null) {
        return [];
    }

    // $s — строковое представление JSON
    $s = trim((string)$rolesJson);
    if ($s === '') {
        return [];
    }

    // $arr — результат json_decode
    $arr = json_decode($s, true);
    if (!is_array($arr)) {
        return [];
    }

    // $out — нормализованный список ролей
    $out = [];
    foreach ($arr as $r) {
        // $r — текущая роль
        $r = trim((string)$r);
        if ($r !== '') {
            $out[] = $r;
        }
    }

    return $out;
}

/**
 * Возвращает пункты меню админки по роли пользователя.
 *
 * Берёт ТОЛЬКО из таблицы modules:
 *  - enabled = 1
 *  - menu = 1
 *  - фильтр по roles:
 *      - если roles пустые -> доступ всем ролям
 *      - если roles не пустые -> только перечисленным
 *
 * @param string $userRole Роль текущего пользователя (admin|manager|user...)
 * @return array<int, array<string,mixed>> Пункты меню
 */
function modules_get_menu(string $userRole): array
{
    // $userRole — роль пользователя для фильтрации
    $userRole = trim($userRole);

    // $pdo — соединение с БД
    $pdo = db();

    // $sql — запрос на выборку модулей для меню
    $sql = "
        SELECT code, name, icon, sort, roles
        FROM modules
        WHERE enabled = 1 AND menu = 1
        ORDER BY sort ASC, id ASC
    ";

    // $stmt — подготовленный запрос
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    // $items — итоговый список меню
    $items = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // $code — код модуля
        $code = (string)($row['code'] ?? '');
        if ($code === '') {
            continue;
        }

        // $roles — список ролей, которым разрешён доступ к модулю
        $roles = modules_parse_roles($row['roles'] ?? null);

        // Если roles задан и роль пользователя не входит — пропускаем
        if (count($roles) > 0 && $userRole !== '' && !in_array($userRole, $roles, true)) {
            continue;
        }

        // $title — отображаемое имя модуля
        $title = (string)($row['name'] ?? $code);

        // $href — каноническая ссылка на модуль
        $href = '/adm/index.php?m=' . urlencode($code);

        // $icon — CSS-класс bootstrap-icons
        $icon = modules_norm_icon_class((string)($row['icon'] ?? ''));

        // $items[] — добавляем пункт меню
        $items[] = [
            'code'       => $code,
            'title'      => $title,
            'href'       => $href,
            'sort'       => (int)($row['sort'] ?? 100),
            'icon'       => $icon,

            // Совместимость: если твой шаблон ждёт icon_group — даём дефолт
            'icon_group' => 'neutral',
        ];
    }

    return $items;
}

/**
 * Роли доступа к модулю берём ТОЛЬКО из таблицы modules.
 *
 * ЛОГИКА:
 *  - если записи нет -> []
 *  - если enabled=0 -> []
 *  - если roles пустые/NULL -> [] означает "доступ всем" решается в acl_guard (или отдельно)
 *
 * ВАЖНО:
 *  - Я оставляю поведение "roles пустые -> []"
 *    а интерпретацию (всем/никому) решай в acl_guard по канону.
 *
 * @param string $moduleCode Код модуля
 * @return array<int,string> Разрешённые роли
 */
function module_allowed_roles(string $moduleCode): array
{
    // $moduleCode — код модуля
    $moduleCode = trim($moduleCode);
    if ($moduleCode === '') {
        return [];
    }

    // $pdo — соединение с БД
    $pdo = db();

    // $stmt — запрос на роли модуля, только если enabled=1
    $stmt = $pdo->prepare("
        SELECT roles
        FROM modules
        WHERE code = :code AND enabled = 1
        LIMIT 1
    ");

    // $params — параметры запроса
    $params = [':code' => $moduleCode];
    $stmt->execute($params);

    // $row — строка результата
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [];
    }

    return modules_parse_roles($row['roles'] ?? null);
}

/**
 * Проверяет: включён ли модуль в БД.
 *
 * @param string $moduleCode Код модуля
 * @return bool true если enabled=1
 */
function module_is_enabled(string $moduleCode): bool
{
    // $moduleCode — код модуля
    $moduleCode = trim($moduleCode);
    if ($moduleCode === '') {
        return false;
    }

    // $pdo — соединение с БД
    $pdo = db();

    // $stmt — запрос на enabled
    $stmt = $pdo->prepare("
        SELECT enabled
        FROM modules
        WHERE code = :code
        LIMIT 1
    ");

    // $params — параметры запроса
    $params = [':code' => $moduleCode];
    $stmt->execute($params);

    // $row — строка результата
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    return ((int)($row['enabled'] ?? 0) === 1);
}

/**
 * modules_protected_codes()
 * Возвращает список кодов модулей, которые нельзя отключать.
 *
 * Это системное правило (CORE), а не UI.
 *
 * @return array<int,string>
 */
function modules_protected_codes(): array
{
  // $codes — коды защищённых модулей
  $codes = [
    'auth',     // авторизация — без неё админка мертва
    'modules',  // управление модулями — иначе можно “отпилить ветку”
  ];

  return $codes;
}

/**
 * modules_is_protected()
 * Проверяет, защищён ли модуль от отключения.
 *
 * @param string $code Код модуля
 * @return bool true если защищён
 */
function modules_is_protected(string $code): bool
{
  // $code — нормализуем
  $code = trim($code);
  if ($code === '') return false;

  // $protected — список защищённых кодов
  $protected = modules_protected_codes();

  return in_array($code, $protected, true);
}
