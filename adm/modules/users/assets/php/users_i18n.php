<?php
/**
 * FILE: /adm/modules/users/assets/php/users_i18n.php
 * ROLE: Вспомогательные функции переводов модуля users.
 * CONTAINS:
 *  - users_t($key, $replace) — получить перевод и выполнить подстановки.
 *  - users_status_label($status) — вернуть локализованный статус пользователя.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

if (!function_exists('users_t')) {
  /**
   * users_t()
   * Возвращает локализованную строку модуля users.
   *
   * @param string $key
   * @param array<string,string|int|float> $replace
   * @return string
   */
  function users_t(string $key, array $replace = []): string
  {
    $text = function_exists('t') ? t($key) : $key;
    if (!$replace) {
      return $text;
    }

    /**
     * $map — карта подстановок вида ['{name}' => 'Иван'].
     */
    $map = [];
    foreach ($replace as $k => $v) {
      $map['{' . $k . '}'] = (string)$v;
    }

    return strtr($text, $map);
  }
}

if (!function_exists('users_status_label')) {
  /**
   * users_status_label()
   * Возвращает локализованную подпись статуса пользователя.
   *
   * @param string $status
   * @return string
   */
  function users_status_label(string $status): string
  {
    return ($status === 'blocked')
      ? users_t('users.status_blocked')
      : users_t('users.status_active');
  }
}
