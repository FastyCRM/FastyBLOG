<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_i18n.php
 * ROLE: I18N-хелпер модуля stopbot.
 * CONTAINS:
 *  - stopbot_t($key, $replace) — получить строку перевода с подстановками.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

if (!function_exists('stopbot_t')) {
  /**
   * stopbot_t()
   * Возвращает локализованную строку модуля.
   * Если перевод не найден, вернёт сам ключ.
   *
   * @param string $key
   * @param array<string,string|int|float> $replace
   * @return string
   */
  function stopbot_t(string $key, array $replace = []): string
  {
    $text = function_exists('t') ? t($key) : $key;
    if (!$replace) {
      return $text;
    }

    /**
     * $map — карта подстановок вида ['{id}' => '12'].
     */
    $map = [];
    foreach ($replace as $k => $v) {
      $map['{' . $k . '}'] = (string)$v;
    }

    return strtr($text, $map);
  }
}