<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_i18n.php
 * ROLE: Вспомогательные функции переводов модуля clients.
 * CONTAINS:
 *  - clients_t($key, $replace) - получить перевод и выполнить подстановки.
 *  - clients_status_label($status) - вернуть локализованный статус клиента.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

if (!function_exists('clients_t')) {
  /**
   * clients_t()
   * Возвращает локализованную строку модуля clients.
   *
   * @param string $key
   * @param array<string,string|int|float> $replace
   * @return string
   */
  function clients_t(string $key, array $replace = []): string
  {
    $text = function_exists('t') ? t($key) : $key;
    if (!$replace) {
      return $text;
    }

    /**
     * $map - карта подстановок вида ['{id}' => '15'].
     */
    $map = [];
    foreach ($replace as $k => $v) {
      $map['{' . $k . '}'] = (string)$v;
    }

    return strtr($text, $map);
  }
}

if (!function_exists('clients_status_label')) {
  /**
   * clients_status_label()
   * Возвращает локализованную подпись статуса клиента.
   *
   * @param string $status
   * @return string
   */
  function clients_status_label(string $status): string
  {
    return ($status === 'blocked')
      ? clients_t('clients.status_blocked')
      : clients_t('clients.status_active');
  }
}
