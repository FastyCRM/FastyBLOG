<?php
/**
 * FILE: /core/subscription_guard.php
 * ROLE: CORE gatekeeper (ограничение доступа в админку по подписке)
 * CONNECTIONS:
 *  - ROOT_PATH (из /core/bootstrap.php)
 *  - db() (из /core/db.php)
 *  - auth_user_id() (из /core/auth.php)
 *  - flash() (из /core/flash.php)
 *
 * STUB MODE:
 *  - Включается ТОЛЬКО если модуль subguard включён в БД (modules.enabled=1).
 *  - Если включён — читаем /storage/subscription.json:
 *      { "enabled": 1 } -> пускаем
 *      иначе -> режем доступ, флешим причину и редиректим на auth
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * subscription_guard()
 * Главная точка входа для проверки подписки.
 *
 * @return void
 */
function subscription_guard(): void
{
  // $uid — текущий пользователь (если нет — не лезем сюда)
  $uid = function_exists('auth_user_id') ? auth_user_id() : null;
  if (!$uid) return;

  // $guardEnabled — включён ли модуль subguard в БД
  $guardEnabled = subscription_guard_module_enabled();
  if (!$guardEnabled) return;

  // $active — активна ли подписка по локальному JSON
  $active = subscription_guard_local_enabled();
  if ($active) return;

  // блокируем доступ
  subscription_guard_block('Подписка не активна');
}

/**
 * subscription_guard_module_enabled()
 * Проверяет в БД: включён ли модуль subguard.
 *
 * ВАЖНО:
 *  - Если БД упала — fail-open (не блокируем), чтобы не словить лок-аут.
 *
 * @return bool
 */
function subscription_guard_module_enabled(): bool
{
  // если db() нет — guard выключен
  if (!function_exists('db')) return false;

  try {
    // $pdo — подключение к БД
    $pdo = db();

    // $st — запрос enabled по коду модуля
    $st = $pdo->prepare("SELECT enabled FROM modules WHERE code = :code LIMIT 1");
    $st->execute([':code' => 'subguard']);

    // $row — строка результата
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;

    return ((int)($row['enabled'] ?? 0) === 1);
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * subscription_guard_local_enabled()
 * Читает локальный флаг из /storage/subscription.json
 *
 * Формат:
 *  { "enabled": 1 }
 *
 * @return bool
 */
function subscription_guard_local_enabled(): bool
{
  // $path — путь к JSON-файлу подписки
  $path = ROOT_PATH . '/storage/subscription.json';

  if (!is_file($path)) return false;

  // $raw — содержимое файла
  $raw = @file_get_contents($path);
  if ($raw === false || trim($raw) === '') return false;

  // $data — декодированный JSON
  $data = json_decode($raw, true);
  if (!is_array($data)) return false;

  // $enabled — значение флага
  $enabled = $data['enabled'] ?? 0;

  if ($enabled === true) return true;

  return ((int)$enabled === 1);
}

/**
 * subscription_guard_block()
 * Блокирует доступ: показывает flash, разлогинивает и редиректит на auth.
 *
 * @param string $message
 * @return void
 */
function subscription_guard_block(string $message): void
{
  // Кладём системное сообщение (твой новый flash)
  if (function_exists('flash')) {
    flash($message, 'danger', 1);
  }

  // ВАЖНО: разлогиниваем, чтобы не застревать в состоянии "залогинен, но запрещено"
  if (function_exists('auth_logout')) {
    auth_logout();
  }

  // Редиректим на auth
  $location = '/adm/index.php?m=auth';

  if (!headers_sent()) {
    header('Location: ' . $location, true, 302);
    exit;
  }

  http_response_code(402);
  exit($message);
}
