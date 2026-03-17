<?php
/**
 * FILE: /core/i18n.php
 * ROLE: Инфраструктура мультиязычности (i18n) на словарях проекта.
 * CONNECTIONS:
 *  - /lang/<lang>/common.php
 *  - /adm/modules/<module>/lang/<lang>.php
 *  - /core/response.php (redirect, safe_return_url)
 *  - /core/csrf.php (csrf_check)
 *
 * CONTAINS:
 *  - load_language($lang) — загружает словари common + словарь активного модуля
 *  - t($key) — возвращает перевод по ключу (module -> common -> key)
 *  - i18n_handle_switch_request() — обрабатывает POST переключения языка из UI
 */

declare(strict_types=1);

if (!function_exists('i18n_supported_languages')) {
  /**
   * i18n_supported_languages()
   * Возвращает список поддерживаемых языков.
   *
   * @return array<int,string>
   */
  function i18n_supported_languages(): array
  {
    return ['ru', 'en'];
  }

  /**
   * i18n_normalize_language()
   * Нормализует входной код языка до поддерживаемого значения.
   *
   * @param string $lang
   * @return string
   */
  function i18n_normalize_language(string $lang): string
  {
    $lang = strtolower(trim($lang));
    if ($lang === '') {
      return 'ru';
    }

    return in_array($lang, i18n_supported_languages(), true) ? $lang : 'ru';
  }

  /**
   * i18n_module_code()
   * Возвращает код активного модуля для загрузки модульного словаря.
   *
   * Приоритет:
   * 1) Явно переданный контекст через $GLOBALS['I18N_MODULE_CODE'].
   * 2) Параметр m из URL.
   *
   * @return string
   */
  function i18n_module_code(): string
  {
    $module = '';

    if (isset($GLOBALS['I18N_MODULE_CODE'])) {
      $module = (string)$GLOBALS['I18N_MODULE_CODE'];
    } else {
      $module = (string)($_GET['m'] ?? '');
    }

    $module = preg_replace('~[^a-z0-9_]+~i', '', $module);
    return trim((string)$module);
  }

  /**
   * i18n_load_dict_file()
   * Подгружает словарь из PHP-файла и гарантирует формат массива.
   *
   * @param string $path
   * @return array<string,string>
   */
  function i18n_load_dict_file(string $path): array
  {
    if (!is_file($path)) {
      return [];
    }

    $raw = require $path;
    if (!is_array($raw)) {
      return [];
    }

    /**
     * $dict — нормализованный словарь string => string.
     */
    $dict = [];
    foreach ($raw as $key => $value) {
      $k = trim((string)$key);
      if ($k === '') continue;
      if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
        $dict[$k] = (string)$value;
      }
    }

    return $dict;
  }

  /**
   * load_language()
   * Загружает словари текущего языка:
   *  - общий: /lang/<lang>/common.php
   *  - модульный: /adm/modules/<module>/lang/<lang>.php
   *
   * Состояние сохраняется в глобальном контексте и в сессии.
   *
   * @param mixed $lang
   * @return void
   */
  function load_language($lang): void
  {
    $normalizedLang = i18n_normalize_language((string)$lang);
    $_SESSION['lang'] = $normalizedLang;

    /**
     * $commonPath — путь до общего словаря.
     */
    $commonPath = ROOT_PATH . '/lang/' . $normalizedLang . '/common.php';
    /**
     * $commonDict — общий словарь.
     */
    $commonDict = i18n_load_dict_file($commonPath);

    /**
     * $moduleCode — код активного модуля.
     */
    $moduleCode = i18n_module_code();
    /**
     * $moduleDict — словарь модуля (если есть).
     */
    $moduleDict = [];

    if ($moduleCode !== '') {
      $modulePath = ROOT_PATH . '/adm/modules/' . $moduleCode . '/lang/' . $normalizedLang . '.php';
      $moduleDict = i18n_load_dict_file($modulePath);
    }

    $GLOBALS['I18N_LANG'] = $normalizedLang;
    $GLOBALS['I18N_DICT_COMMON'] = $commonDict;
    $GLOBALS['I18N_DICT_MODULE'] = $moduleDict;
    $GLOBALS['I18N_MODULE_LOADED'] = $moduleCode;
  }

  /**
   * t()
   * Возвращает перевод по ключу.
   *
   * Приоритет:
   * 1) словарь активного модуля
   * 2) общий словарь
   * 3) сам ключ
   *
   * @param string $key
   * @return string
   */
  function t(string $key): string
  {
    $key = trim($key);
    if ($key === '') {
      return '';
    }

    if (!isset($GLOBALS['I18N_DICT_COMMON']) || !is_array($GLOBALS['I18N_DICT_COMMON'])) {
      load_language((string)($_SESSION['lang'] ?? 'ru'));
    }

    /**
     * $moduleDict — модульный словарь.
     *
     * @var array<string,string> $moduleDict
     */
    $moduleDict = (array)($GLOBALS['I18N_DICT_MODULE'] ?? []);
    if (array_key_exists($key, $moduleDict)) {
      return (string)$moduleDict[$key];
    }

    /**
     * $commonDict — общий словарь.
     *
     * @var array<string,string> $commonDict
     */
    $commonDict = (array)($GLOBALS['I18N_DICT_COMMON'] ?? []);
    if (array_key_exists($key, $commonDict)) {
      return (string)$commonDict[$key];
    }

    return $key;
  }

  /**
   * i18n_lang()
   * Возвращает текущий активный язык интерфейса.
   *
   * @return string
   */
  function i18n_lang(): string
  {
    return i18n_normalize_language((string)($GLOBALS['I18N_LANG'] ?? ($_SESSION['lang'] ?? 'ru')));
  }

  /**
   * i18n_handle_switch_request()
   * Обрабатывает POST-переключение языка из UI (выпадающий список в shell).
   *
   * Протокол:
   *  - i18n_switch = 1
   *  - lang = ru|en
   *  - csrf = csrf_token()
   *  - return_url = текущий URL
   *
   * @return void
   */
  function i18n_handle_switch_request(): void
  {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
      return;
    }

    if ((string)($_POST['i18n_switch'] ?? '') !== '1') {
      return;
    }

    $csrf = (string)($_POST['csrf'] ?? '');
    csrf_check($csrf);

    $lang = (string)($_POST['lang'] ?? 'ru');
    load_language($lang);

    $fallback = '/adm/index.php';
    $returnUrl = (string)($_POST['return_url'] ?? ($_SERVER['REQUEST_URI'] ?? $fallback));
    $safeUrl = safe_return_url($returnUrl, $fallback);
    redirect($safeUrl);
  }
}
