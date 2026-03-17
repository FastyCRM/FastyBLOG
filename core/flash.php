<?php
/**
 * FILE: /core/flash.php
 * ROLE: Flash-уведомления (один показ после redirect) в виде очереди
 * CONNECTIONS:
 *  - /core/session.php (session_get/session_set)
 *
 * API:
 *  - flash($text, $bg, $beep)       // добавить сообщение в очередь
 *  - flash_pull()                  // забрать очередь и очистить
 *
 * NOTES:
 *  - ЛЕГАСИ flash_set/flash_get УБРАНО (чтобы не нагородить дальше).
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * flash()
 * Универсальный вызов: flash("Тест", "danger", 1)
 *
 * @param string $text   // $text — текст уведомления
 * @param string $bg     // $bg — тип/фон: info|ok|warn|danger|accent или hex "#RRGGBB"
 * @param int    $beep   // $beep — 1=звук, 0=без звука
 * @return void
 */
function flash(string $text, string $bg = 'info', int $beep = 1): void
{
  // $queue — текущая очередь флеш-сообщений
  $queue = (array)session_get('_flash', []);

  // $item — элемент очереди
  $item = [
    'text' => (string)$text,
    'bg'   => (string)$bg,
    'beep' => $beep ? 1 : 0,
  ];

  $queue[] = $item;

  // сохраняем обратно
  session_set('_flash', $queue);
}

/**
 * flash_pull()
 * Забирает очередь и очищает (одноразовый показ).
 *
 * @return array<int, array<string,mixed>>
 */
function flash_pull(): array
{
  // $queue — очередь флеш-сообщений
  $queue = (array)session_get('_flash', []);

  // очищаем
  session_set('_flash', []);

  return $queue;
}
