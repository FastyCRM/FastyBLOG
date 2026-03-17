<?php
/**
 * FILE: /core/config.php
 * ROLE: Конфигурация проекта (БД, безопасность, аудит)
 * CONNECTIONS:
 *  - используется в /adm/core/bootstrap.php (складывается в $GLOBALS['APP_CONFIG'])
 *  - читается через app_config() из /core/session.php
 *
 * NOTES:
 *  - Никакой логики. Только параметры.
 *  - Любые секреты (app_secret) должны быть длинными и случайными.
 */

declare(strict_types=1);

/**
 * АВТОВЫБОР ПРОФИЛЯ БД ПО ОКРУЖЕНИЮ
 *
 * Логика:
 * 1) Если задан APP_DB_PROFILE, используем его принудительно.
 * 2) Иначе определяем профиль по пути проекта:
 *    - OpenServer (Windows) -> openserver
 *    - MAMP (macOS)         -> mamp
 *    - всё остальное        -> hosting
 *
 * Поддерживаемые профили:
 * - openserver
 * - mamp
 * - hosting
 */
$configRootPath = str_replace('\\', '/', (string)(defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__)));
$configDocRoot = str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
$configScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
$configContext = strtolower($configRootPath . ' ' . $configDocRoot . ' ' . $configScript);

$forcedProfile = strtolower(trim((string)(getenv('APP_DB_PROFILE') ?: '')));

$dbProfile = 'hosting';
if (strpos($configContext, '/openserver/') !== false || strpos($configContext, 'd:/openserver/') !== false) {
  $dbProfile = 'openserver';
} elseif (strpos($configContext, '/applications/mamp/') !== false || strpos($configContext, '/mamp/htdocs/') !== false) {
  $dbProfile = 'mamp';
}
if (in_array($forcedProfile, ['openserver', 'mamp', 'hosting'], true)) {
  $dbProfile = $forcedProfile;
}

/**
 * Профили БД для разных окружений.
 */
$dbProfiles = [
  'openserver' => [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'crm2026blog',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
  ],
  'mamp' => [
    'host' => 'localhost',
    'port' => 8889,
    'name' => 'crm2026blog',
    'user' => 'root',
    'pass' => 'root',
    'charset' => 'utf8mb4',
  ],
  'hosting' => [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'u0589068_crm2026blog',
    'user' => 'u0589068_crm_adm',
    'pass' => 'FreeDOMJOB1!2@',
    'charset' => 'utf8mb4',
  ],
];

/**
 * Можно переопределить профили локальным файлом:
 * /core/config.db.local.php
 *
 * Формат файла:
 * <?php return ['openserver'=>[...], 'mamp'=>[...], 'hosting'=>[...]];
 */
$dbLocalPath = __DIR__ . '/config.db.local.php';
if (is_file($dbLocalPath)) {
  $dbLocal = require $dbLocalPath;
  if (is_array($dbLocal)) {
    foreach (['openserver', 'mamp', 'hosting'] as $profileName) {
      if (isset($dbLocal[$profileName]) && is_array($dbLocal[$profileName])) {
        $dbProfiles[$profileName] = array_merge($dbProfiles[$profileName], $dbLocal[$profileName]);
      }
    }
  }
}

$dbSelected = $dbProfiles[$dbProfile] ?? $dbProfiles['openserver'];

return [
  /**
   * База данных MySQL
   * port обязателен, чтобы не было сюрпризов на разных окружениях (MAMP/OpenServer/host)
   */
  // 'db' => [
  //   'host' => 'localhost',
  //   'port' => 3306,
  //   'name' => 'crm2026blog',
  //   'user' => 'root',
  //   'pass' => 'root',
  //   'charset' => 'utf8mb4',
  // ],
  'db' => $dbSelected,
  // 'db' => [
  //   'host' => 'localhost',
  //   'name' => 'u0589068_crm2026blog',
  //   // 'port' => 8889,
  //   'user' => 'u0589068_crm_adm',
  //   'pass' => 'FreeDOMJOB1!2@',
  //   'charset' => 'utf8mb4',
  // ],
  /**
   * Настройки безопасности
   */
  'security' => [
    /**
     * Главный секрет приложения.
     * Используется для подписи cookies (remember) и любых HMAC-подписей.
     * Требование: 64+ символа, случайная строка.
     */
    'app_secret' => 'CHANGE_ME__LONG_RANDOM_SECRET__64+_CHARS',

    /**
     * Имя cookie PHP-сессии.
     * Должно быть уникальным для проекта, чтобы не пересекаться с другими сайтами на домене.
     */
    'session_cookie_name' => 'crm2026_sid',

    /**
     * Имя remember-cookie (если используем восстановление входа).
     */
    'remember_cookie_name' => 'crm2026_remember',

    /**
     * Срок жизни remember-cookie (в секундах)
     */
    'remember_lifetime_sec' => 60 * 60 * 24 * 14, // 14 дней

    /**
     * Ключ, под которым CSRF хранится в $_SESSION
     */
    'csrf_session_key' => 'csrf_token',
  ],

  /**
   * Шифрование чувствительных данных (логины/пароли)
   */
  'crypto' => [
    /**
     * key — основной секрет (строка). Если пусто, используется key_file.
     * Рекомендуется задать длинный случайный ключ и держать в конфиге.
     */
    'key' => '',

    /**
     * key_file — путь к файлу ключа (base64 32 байта).
     * Если key пустой — ключ будет создан автоматически при первом вызове.
     */
    'key_file' => ROOT_PATH . '/storage/crypto.key',
  ],

  /**
   * Почта (SMTP)
   * ВАЖНО: это НЕ mail(), это прямой SMTP.
   */
  'mail' => [
    'enabled' => true,
    'driver'  => 'smtp',

    // SMTP сервер (пример: smtp.yandex.ru / smtp.gmail.com / smtp.timeweb.ru)
    'host' => 'smtp.yandex.ru',
    'port' => 587,

    // secure: 'tls' (587) или 'ssl' (465) или '' (без шифрования)
    'secure' => 'tls',

    // учётка SMTP
    'user' => 'albosoft@yandex.ru',
    'pass' => 'xmxyvynipyhkvqzz',

    // From по умолчанию
    'from_email' => 'albosoft@yandex.ru',
    'from_name'  => 'CRM2026',

    // Для EHLO
    'ehlo' => 'crm2026',

    // text/plain по умолчанию
    'content_type' => 'text/plain',

    // Таймаут сокета
    'timeout' => 5,
  ],

  /**
   * PDF (генерация печатных форм)
   */
  'pdf' => [
    /**
     * Включить/выключить PDF-сервис
     */
    'enabled' => true,

    /**
     * Базовая папка хранения PDF (внутри проекта)
     */
    'storage_dir' => ROOT_PATH . '/storage/pdf',

    /**
     * Параметры страницы по умолчанию
     */
    'default_page_size' => 'A4',
    'default_orientation' => 'portrait',
    'default_margin_mm' => 10,

    /**
     * Шрифт по умолчанию
     */
    'default_font' => 'DejaVuSans',
    'default_font_size' => 11,
    'font_path' => ROOT_PATH . '/core/assets/fonts/DejaVuSans.ttf',

    /**
     * Разрешить сохранение в storage/pdf
     */
    'allow_save' => true,
  ],

  /**
   * Авторизация: ограничения по попыткам входа (защита от перебора)
   */
  'auth' => [
    /**
     * Сколько неуспешных попыток допускается за окно времени
     */
    'login_attempt_limit' => 5,

    /**
     * Окно времени для подсчёта попыток (сек)
     * 600 = 10 минут
     */
    'login_attempt_window_sec' => 600,

    /**
     * Время блокировки после превышения лимита (сек)
     * 900 = 15 минут
     */
    'lockout_sec' => 900,
  ],

  /**
   * Аудит/логирование
   */
  'audit' => [
    /**
   * Файл логов (JSONL). Запись идёт всегда; если БД недоступна — файл остаётся единственным логом.
   * Формат: JSONL (одна строка = одно событие).
     *
     * ВАЖНО: папка /logs должна существовать и быть доступна на запись.
     */
    'fallback_file' => __DIR__ . '/../logs/audit-fallback.log',
  ]
  ,
  /**
   * Telegram-бот (core-интеграция)
   */
  'telegram' => [
    /**
     * Глобальный переключатель интеграции
     */
    'enabled' => false,

    /**
     * Токен бота от BotFather
     */
    'bot_token' => '',

    /**
     * Секрет для проверки заголовка webhook:
     * X-Telegram-Bot-Api-Secret-Token
     */
    'webhook_secret' => '',

    /**
     * Публичный HTTPS URL webhook-страницы.
     * Пример: https://example.com/core/telegram_webhook.php
     */
    'webhook_url' => '',

    /**
     * Опциональный файл пользовательского обработчика webhook.
     * Если файл существует и определяет функцию tg_webhook_on_update(array $update),
     * она будет вызвана после валидации запроса.
     */
    'webhook_handler_file' => ROOT_PATH . '/storage/telegram_webhook_handler.php',

    /**
     * Настройки запросов к Telegram API
     */
    'default_parse_mode' => 'HTML',
    'connect_timeout' => 5,
    'timeout' => 20,
  ],

  /**
   * Внутренний API (обмен между модулями и site)
   */
  'internal_api' => [
    /**
     * key — если пусто, доступ не ограничивается.
     * Можно передавать через заголовок X-Internal-Api-Key или query ?key=
     */
    'key' => '',
  ],
];
